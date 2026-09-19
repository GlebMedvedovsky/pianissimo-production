<?php
namespace App\Services;
use App\Models\{Project,Track,MediaAsset,CvEntry,Setting,ExternalService,Publication};
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ManifestExporter
{
    private function object(?array $a): object { return (object)($a??[]); }
    private function links(?array $a): object { return (object) array_filter(Arr::only($a??[],['spotify','tidal','deezer','appleMusic']),fn($v)=>$v!==null && $v!==''); }
    private function optional(array $a): array { return array_filter($a,fn($v)=>$v!==null && $v!==''); }
    private function media(string $key): array { return Arr::only(Setting::read($key)['media']??['image'=>null,'video'=>null,'poster'=>null],['image','video','poster']); }
    public function package(): array
    {
        $trackIds=Track::where('status','published')->where('library_visible',true)->pluck('id')->all(); $assetIds=[]; $projects=[];
        foreach (Project::where('status','published')->orderBy('sort_order')->orderBy('id')->with(['memberships','mediaAssignments','soundtrack'])->get() as $p) {
            if($p->soundtrack_order_pending) throw new DomainException("{$p->id}: Track-Reihenfolge muss vor der Auswahl bestätigt werden.");
            $members=[];
            foreach ($p->memberships as $v) {
                if (!in_array($v->direction,['music','directing'])) throw new DomainException("{$p->id}: ungültige Richtung.");
                $members[$v->direction]=['order'=>$v->sort_order,'role'=>$v->role,'coverAssetId'=>$v->cover_asset_id,'crop'=>$v->crop,...$this->optional(['fit'=>$v->fit,'hoverAssetId'=>$v->hover_asset_id])];
                $assetIds[]=$v->cover_asset_id; $assetIds[]=$v->hover_asset_id;
            }
            $gallery=[]; $optional=[]; $slots=[];
            foreach ($p->mediaAssignments as $v) {
                if (in_array($v->slot,['soundtrack_cover','other'])) $assetIds[]=$v->media_asset_id;
                if ($v->slot==='gallery') { $gallery[]=$v->media_asset_id; $assetIds[]=$v->media_asset_id; }
                if (in_array($v->slot,['trailer','trailer_poster'])) {
                    if (isset($slots[$v->slot])) throw new DomainException("{$p->id}: mehrfacher {$v->slot}.");
                    $slots[$v->slot]=true;
                    $optional[$v->slot==='trailer'?'localTrailerAssetId':'localTrailerPosterId']=$v->media_asset_id;
                    $assetIds[]=$v->media_asset_id;
                }
            }
            $soundtrack=[];
            foreach ($p->soundtrack as $v) if (Track::whereKey($v->track_id)->where('status','published')->exists()) {
                if ($v->sort_order===null) throw new DomainException("{$p->id}: Track-Reihenfolge ist noch offen.");
                $soundtrack[]=['trackId'=>$v->track_id,'order'=>$v->sort_order]; $trackIds[]=$v->track_id;
            }
            $projects[]=[
                'id'=>$p->id,'slug'=>$p->slug,'title'=>$p->title,'synopsis'=>$p->synopsis,'status'=>'published','order'=>$p->sort_order,
                'productionType'=>$p->production_type,'year'=>$p->year,'credits'=>$p->credits??[],'categories'=>$p->categories??[],
                'memberships'=>$this->object($members),'awards'=>$p->awards??[],'gallery'=>$gallery,'externalFilm'=>$p->external_film,
                'platformLinks'=>$this->links($p->platform_links),'tracks'=>$soundtrack,'sourceIds'=>[],
                'genres'=>$p->genres??[],'emotions'=>$p->emotions??[],
                ...$this->optional(['yearStatus'=>$p->year_status,'orderStatus'=>$p->order_status,'presentationStatus'=>$p->presentation_status]),...$optional,
                'externalLinks'=>$this->object(array_filter(Arr::only($p->external_links??[],['instagram']))),
            ];
        }
        $tracks=[];
        foreach (Track::whereIn('id',array_unique($trackIds))->orderBy('id')->get() as $t) {
            if ($t->audio_asset_id && !in_array($t->rights_scope,['existing_local_draft','next_draft','public'])) throw new DomainException("{$t->id}: Playback-Nutzung für den nächsten Entwurf ist unbestätigt.");
            $assetIds[]=$t->audio_asset_id;
            $tracks[]=['id'=>$t->id,'title'=>$t->title,'audioAssetId'=>$t->audio_asset_id,'duration'=>$t->duration,'peaks'=>$t->peaks,
                'genres'=>$t->genres,'emotions'=>$t->emotions,'platformOverrides'=>$this->links($t->platform_overrides),'status'=>'published',
                ...($t->library_visible?['libraryVisible'=>true]:[]),
                ...$this->optional(['audioSha256'=>$t->audio_sha256,'waveformSha256'=>$t->waveform_sha256,'tagSource'=>$t->tag_source])];
        }
        $slots=['hero'=>$this->media('home'),'composerMenu'=>$this->media('music'),'directingMenu'=>$this->media('directing'),'about'=>$this->media('about')];
        foreach ($slots as $values) foreach ($values as $id) $assetIds[]=$id;
        $assetIds[]=Setting::read('seo')['ogAssetId']??null;
        foreach (Project::where('status','published')->get() as $p) $assetIds[]=$p->seo['ogAssetId']??null;
        $assets=[];
        foreach (MediaAsset::whereIn('id',array_filter(array_unique($assetIds)))->orderBy('id')->get() as $a) {
            $assets[]=['id'=>$a->id,'type'=>$a->type,'path'=>$a->path,'alt'=>$a->alt,'crop'=>$a->crop,
                'origin'=>'Curated local derivative','source'=>'Provenance is recorded in the private CMS registry.','state'=>$a->state,
                ...$this->optional(['credit'=>$a->credit,'width'=>$a->width,'height'=>$a->height,'duration'=>$a->duration,'sha256'=>$a->sha256])];
        }
        $cv=[];
        foreach (CvEntry::whereIn('status',['published','needs-confirmation'])->orderBy('sort_order')->orderBy('id')->get() as $r) {
            $cv[]=['date'=>$r->date,'text'=>implode(' — ',array_filter([$r->title,$r->institution,$r->description])),'status'=>$r->status,...$this->optional(['link'=>$r->link,'linkText'=>$r->link_text])];
        }
        $site=Setting::read('site'); $contact=Setting::read('contact'); $about=Setting::read('about');
        $notice=Setting::read('impressum'); $privacy=Setting::read('privacy');
        $manifest=['schemaVersion'=>'1.0.0','mode'=>'prototype','sources'=>[],'assets'=>$assets,'projects'=>$projects,'tracks'=>$tracks,
            'site'=>['language'=>'en','title'=>$site['title']??'','description'=>$site['description']??'',
                'biography'=>($about['visible']??true)?($about['biography']??[]):[],'cv'=>$cv,
                'contacts'=>['email'=>$contact['email']??null,'instagram'=>$contact['instagram']??null,'spotify'=>$contact['spotify']??null,'samples'=>$contact['samples']??null],
                'legal'=>['notice'=>$notice['text']??'','privacy'=>$privacy['text']??'','approved'=>($notice['status']??'draft')==='published' && ($privacy['status']??'draft')==='published'],
                'productionDomain'=>$site['productionDomain']??null,'artistLinks'=>$this->links(Setting::read('social')['artistLinks']??[]),'mediaSlots'=>$slots]];
        app(ContentValidator::class)->validate($manifest);
        $extension=app(SettingsExporter::class)->export();
        return ['manifest'=>$manifest,'settings'=>$extension];
    }
    public function export(bool $dryRun=true, bool $publish=false, ?int $userId=null): array
    {
        // Consistent read across all tables; never writes to the frontend or its input.
        return DB::transaction(function () use ($dryRun,$publish,$userId) {
            $package=$this->package(); $hash=ManifestImporter::hash($package);
            $summary=['hash'=>$hash,'projects'=>count($package['manifest']['projects']),'tracks'=>count($package['manifest']['tracks']),'assets'=>count($package['manifest']['assets'])];
            if ($dryRun) return $summary;
            $relative=config('cms.export_prefix').'/'.($publish?'published':'candidates').'/'.$hash;
            $directory=storage_path('app/private/'.$relative);
            if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) throw new DomainException('Export-Verzeichnis konnte nicht erstellt werden.');
            foreach (['manifest'=>'manifest.json','settings'=>'cms-settings.json'] as $key=>$file) {
                $text=json_encode($package[$key],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                if (is_file($directory.'/'.$file)) {
                    if (file_get_contents($directory.'/'.$file)!==$text) throw new DomainException('Unveränderliches Paket stimmt nicht überein.');
                } else {
                    if (file_put_contents($directory.'/'.$file.'.pending',$text,LOCK_EX)===false || !rename($directory.'/'.$file.'.pending',$directory.'/'.$file)) throw new DomainException('Export konnte nicht atomar gespeichert werden.');
                }
            }
            $publication=Publication::firstOrCreate(['hash'=>$hash,'status'=>$publish?'published':'candidate'],['path'=>$relative,'user_id'=>$userId,'summary'=>$summary]);
            // Local apply uses an authenticated export receipt, never admin credentials.
            if ($userId && !$publish) {
                $receipt=['version'=>1,'candidateHash'=>$hash,'adminUserId'=>$userId,
                    'manifestSha256'=>hash_file('sha256',$directory.'/manifest.json'),
                    'settingsSha256'=>hash_file('sha256',$directory.'/cms-settings.json')];
                $text=json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
                if (file_put_contents($directory.'/receipt.json.pending',$text,LOCK_EX)===false || !rename($directory.'/receipt.json.pending',$directory.'/receipt.json')) throw new DomainException('Export-Beleg konnte nicht gespeichert werden.');
            }
            return [...$summary,'publicationId'=>$publication->id,'path'=>$relative];
        });
    }
}
