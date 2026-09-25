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
    private function profileLinks(?array $a): object { return (object) array_filter(Arr::only($a??[],['imdb','crewUnited','musicHub']),fn($v)=>$v!==null && $v!==''); }
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
        $assetIds[]=Setting::read('about')['collective']['logoAssetId']??null;
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
        $site=Setting::read('site'); $contact=Setting::read('contact'); $about=Setting::read('about'); $social=Setting::read('social'); $consent=Setting::read('consent'); $analytics=Setting::read('analytics');
        $notice=Setting::read('impressum'); $privacy=Setting::read('privacy');
        $manifest=['schemaVersion'=>'1.0.0','mode'=>'prototype','sources'=>[],'assets'=>$assets,'projects'=>$projects,'tracks'=>$tracks,
            'site'=>['language'=>'en','title'=>$site['title']??'','description'=>$site['description']??'',
                'biography'=>($about['visible']??true)?($about['biography']??[]):[],'cv'=>$cv,
                'contacts'=>['email'=>$contact['email']??null,'instagram'=>$contact['instagram']??null,'spotify'=>$contact['spotify']??null,'samples'=>$contact['samples']??null],
                'legal'=>['notice'=>$notice['text']??'','privacy'=>$privacy['text']??'','approved'=>($notice['status']??'draft')==='published' && ($privacy['status']??'draft')==='published'],
                'productionDomain'=>$site['productionDomain']??null,'artistLinks'=>$this->links($social['artistLinks']??[]),
                'profileLinks'=>$this->profileLinks($social['profileLinks']??[]),
                'socialLinks'=>array_values(array_map(fn($link)=>Arr::only($link,['provider','label','url','visible','sort_order']),$social['links']??[])),
                'consent'=>['version'=>(int)($consent['version']??1),'necessary'=>true,'externalMedia'=>(bool)($consent['externalMedia']??true),'analytics'=>(bool)($consent['analytics']??false),'marketing'=>(bool)($consent['marketing']??false),'bannerText'=>(string)($consent['bannerText']??''),'privacyLink'=>(string)($consent['privacyLink']??'/privacy/')],
                'analytics'=>['enabled'=>(bool)($analytics['enabled']??false),'provider'=>(string)($analytics['provider']??'none'),'measurementId'=>($analytics['provider']??null)==='ga4'?($analytics['measurementId']??null):null,'siteDomain'=>($analytics['provider']??null)==='plausible'?($analytics['siteDomain']??null):null],
                ...(!empty($about['collective']['name']) && !empty($about['collective']['url']) && !empty($about['collective']['membershipText'])?['collective'=>[
                    'name'=>$about['collective']['name'],'url'=>$about['collective']['url'],'logoAssetId'=>$about['collective']['logoAssetId']??null,'membershipText'=>$about['collective']['membershipText'],
                ]]:[]),'mediaSlots'=>$slots]];
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
            $snapshotFile=$directory.'/cms-draft-snapshot.json';
            $snapshot=json_encode(app(DraftStateSnapshot::class)->capture($hash),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(is_file($snapshotFile)){if(file_get_contents($snapshotFile)!==$snapshot){if(\App\Models\Publication::where('hash',$hash)->latest('id')->value('status')!=='stale')throw new DomainException('Unveränderlicher CMS-Stand stimmt nicht überein.');$pending=$snapshotFile.'.pending';if(file_put_contents($pending,$snapshot,LOCK_EX)===false||!rename($pending,$snapshotFile))throw new DomainException('CMS-Stand konnte nicht erneuert werden.');}}
            elseif(file_put_contents($snapshotFile.'.pending',$snapshot,LOCK_EX)===false||!rename($snapshotFile.'.pending',$snapshotFile))throw new DomainException('CMS-Stand konnte nicht gespeichert werden.');
            $media=[];
            foreach ($package['manifest']['assets'] as $asset) {
                $record=MediaAsset::find($asset['id']);
                if (!$record?->storage_path) continue;
                if (!str_starts_with($record->path,'media/cms/')) throw new DomainException($record->id.': verwaltetes Medium liegt außerhalb des freigegebenen Pfads.');
                $source=storage_path('app/private/'.$record->storage_path);
                if (!is_file($source) || hash_file('sha256',$source)!==$record->sha256) throw new DomainException($record->id.': privates Original stimmt nicht mit der Prüfsumme überein.');
                $mediaRelative=substr($record->path,10); $target=$directory.'/media/'.$mediaRelative;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target),0750,true) && !is_dir(dirname($target))) throw new DomainException('Kandidaten-Medienverzeichnis konnte nicht erstellt werden.');
                if (is_file($target)) { if (hash_file('sha256',$target)!==$record->sha256) throw new DomainException('Unveränderliches Medien-Payload stimmt nicht überein.'); }
                elseif (!copy($source,$target)) throw new DomainException('Medien-Payload konnte nicht gespeichert werden.');
                $media[]=['path'=>$record->path,'sha256'=>$record->sha256,'size'=>(int)filesize($source),'assetId'=>$record->id];
            }
            $managedDeletes=MediaAsset::onlyTrashed()->where('path','like','media/cms/%')->pluck('path')->sort()->values()->all();
            $publication=Publication::firstOrCreate(['hash'=>$hash,'status'=>$publish?'published':'candidate'],['path'=>$relative,'user_id'=>$userId,'summary'=>$summary]);
            // Local apply uses an authenticated export receipt, never admin credentials.
            if ($userId && !$publish) {
                $receipt=['version'=>1,'candidateHash'=>$hash,'adminUserId'=>$userId,
                    'manifestSha256'=>hash_file('sha256',$directory.'/manifest.json'),
                    'settingsSha256'=>hash_file('sha256',$directory.'/cms-settings.json'),'snapshotSha256'=>hash_file('sha256',$snapshotFile),'media'=>$media,'managedDeletes'=>$managedDeletes];
                $text=json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
                if (file_put_contents($directory.'/receipt.json.pending',$text,LOCK_EX)===false || !rename($directory.'/receipt.json.pending',$directory.'/receipt.json')) throw new DomainException('Export-Beleg konnte nicht gespeichert werden.');
            }
            return [...$summary,'publicationId'=>$publication->id,'path'=>$relative];
        });
    }
}
