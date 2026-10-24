<?php
namespace App\Services;
use App\Models\{MediaAsset,Track,Project,CvEntry,Setting,ImportRecord};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ManifestImporter
{
    public static function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
    public static function snapshot(Model $record): array
    {
        $data=$record->fresh()->attributesToArray();
        unset($data['created_at'],$data['updated_at']);
        if ($record instanceof Project) foreach (['memberships','mediaAssignments','soundtrack'] as $relation) {
            $data[$relation]=$record->$relation()->get()->map(function ($row) {
                $v=$row->attributesToArray(); unset($v['id'],$v['created_at'],$v['updated_at']); return $v;
            })->all();
        }
        return $data;
    }
    public function import(array $m): array
    {
        app(ContentValidator::class)->validate($m);
        return DB::transaction(function () use ($m) {
            $report=['created'=>[],'updated'=>[],'skipped'=>[],'conflicts'=>[]];
            $put=function ($class,$id,$fields,$relations=[]) use (&$report) {
                $key=class_basename($class).':'.$id;
                $inputHash=self::hash([$fields,$relations]);
                $row=$class::find($id); $baseline=ImportRecord::find($key);
                if (!$row && $baseline) { $report['conflicts'][]=$key.' (manually deleted)'; return; }
                if ($row) {
                    if (!$baseline || self::hash(self::snapshot($row))!==$baseline->record_hash) { $report['conflicts'][]=$key; return; }
                    if ($baseline->input_hash===$inputHash) { $report['skipped'][]=$key; return; }
                }
                $kind=$row?'updated':'created';
                $row=$class::firstOrNew(['id'=>$id]);
                $row->fill($fields);
                // The validated source supplies matching playback/waveform as one record.
                // UI playback changes, in contrast, must invalidate the old waveform.
                $row instanceof Track?$row->saveQuietly():$row->save();
                foreach ($relations as $relation=>$rows) { $row->$relation()->delete(); $row->$relation()->createMany($rows); }
                ImportRecord::updateOrCreate(['id'=>$key],['input_hash'=>$inputHash,'record_hash'=>self::hash(self::snapshot($row))]);
                $report[$kind][]=$key;
            };
            foreach ($m['assets'] as $a) $put(MediaAsset::class,$a['id'],[
                'path'=>$a['path'],'type'=>$a['type'],'purpose'=>'other','alt'=>$a['alt'],'crop'=>$a['crop'],
                'credit'=>$a['credit']??null,'source'=>'Existing frontend web derivative; private provenance is not imported.',
                'state'=>$a['state'],'width'=>$a['width']??null,'height'=>$a['height']??null,'duration'=>$a['duration']??null,'sha256'=>$a['sha256']??null,
                'rights_scope'=>'existing_local_draft',
            ]);
            foreach ($m['tracks'] as $t) $put(Track::class,$t['id'],[
                'title'=>$t['title'],'audio_asset_id'=>$t['audioAssetId'],'duration'=>$t['duration'],'peaks'=>$t['peaks'],
                'genres'=>$t['genres'],'emotions'=>$t['emotions'],'platform_overrides'=>$t['platformOverrides'],'status'=>$t['status'],
                'audio_sha256'=>$t['audioSha256']??null,'waveform_sha256'=>$t['waveformSha256']??null,'tag_source'=>$t['tagSource']??null,'rights_scope'=>'existing_local_draft',
                'library_visible'=>$t['libraryVisible']??false,'audio_metadata_error'=>null,
                'audio_metadata_status'=>!empty($t['audioAssetId']) && isset($t['duration'],$t['peaks'],$t['audioSha256'],$t['waveformSha256'])?'ready':'missing',
                'audio_analysis_job_id'=>null,
                'audio_metadata_integrity_sha256'=>!empty($t['audioAssetId']) && isset($t['duration'],$t['peaks'],$t['audioSha256'],$t['waveformSha256'])
                    ?TrackAudioMetadata::metadataIntegritySha256((float)$t['duration'],$t['peaks'],$t['audioSha256'],$t['waveformSha256']):null,
            ]);
            foreach ($m['projects'] as $p) {
                $members=[];
                foreach ($p['memberships'] as $direction=>$v) $members[]=[
                    'direction'=>$direction,'role'=>$v['role'],'sort_order'=>$v['order'],'cover_asset_id'=>$v['coverAssetId'],
                    'hover_asset_id'=>$v['hoverAssetId']??null,'crop'=>$v['crop'],'fit'=>$v['fit']??null,
                ];
                usort($members,fn($a,$b)=>strcmp($a['direction'],$b['direction']));
                $media=[];
                foreach ($p['gallery'] as $i=>$a) $media[]=['media_asset_id'=>$a,'slot'=>'gallery','sort_order'=>$i];
                foreach (['localTrailerAssetId'=>'trailer','localTrailerPosterId'=>'trailer_poster'] as $field=>$slot)
                    if (isset($p[$field])) $media[]=['media_asset_id'=>$p[$field],'slot'=>$slot,'sort_order'=>0];
                $put(Project::class,$p['id'],[
                    'slug'=>$p['slug'],'title'=>$p['title'],'synopsis'=>$p['synopsis'],'status'=>$p['status'],'sort_order'=>$p['order'],
                    'production_type'=>$p['productionType'],'year'=>$p['year'],'credits'=>$p['credits'],'categories'=>$p['categories'],
                    'awards'=>$p['awards'],'platform_links'=>$p['platformLinks'],'external_film'=>$p['externalFilm'],
                    'genres'=>$p['genres']??[],'emotions'=>$p['emotions']??[],'external_links'=>$p['externalLinks']??[],
                    'year_status'=>$p['yearStatus']??null,'order_status'=>$p['orderStatus']??null,'seo'=>[],
                    'presentation_status'=>$p['presentationStatus']??null,
                ],['memberships'=>$members,'mediaAssignments'=>$media,'soundtrack'=>array_map(fn($t)=>['track_id'=>$t['trackId'],'sort_order'=>$t['order']],$p['tracks'])]);
            }
            $site=$m['site'];
            foreach ($site['cv'] as $i=>$cv) $put(CvEntry::class,'cv-'.substr(self::hash([$cv['date'],$cv['text']]),0,16),[
                'date'=>$cv['date'],'title'=>$cv['text'],'link'=>$cv['link']??null,'status'=>$cv['status'],'sort_order'=>$i,
                'link_text'=>$cv['linkText']??null,
            ]);
            $settings=[
                'site'=>['title'=>$site['title'],'description'=>$site['description'],'productionDomain'=>$site['productionDomain'],'language'=>$site['language']],
                'home'=>['media'=>MediaSlotPolicy::hydrate('home',['media'=>$site['mediaSlots']['hero']])['media'],'loop'=>true,'autoplayMuted'=>true],
                'music'=>['media'=>MediaSlotPolicy::hydrate('music',['media'=>$site['mediaSlots']['composerMenu']])['media'],'label'=>'Film Music'],
                'directing'=>['media'=>MediaSlotPolicy::hydrate('directing',['media'=>$site['mediaSlots']['directingMenu']])['media'],'label'=>'Directing'],
                'about'=>['title'=>'About','biography'=>$site['biography'],'media'=>MediaSlotPolicy::hydrate('about',['media'=>$site['mediaSlots']['about']])['media'],'visible'=>true,'theme'=>'follow-section','getInTouch'=>'Get in touch','collective'=>$site['collective']??[]],
                'contact'=>[...$site['contacts'],'recipient'=>null,'footer'=>'','legalIdentity'=>''],
                'social'=>['artistLinks'=>$site['artistLinks']??[],'profileLinks'=>$site['profileLinks']??[],'links'=>$site['socialLinks']??$this->legacySocialLinks($site)],
                'icon-rail'=>IconRail::fromManifestSite($site),
                'impressum'=>['text'=>$site['legal']['notice'],'status'=>$site['legal']['approved']?'published':'draft'],
                'privacy'=>['text'=>$site['legal']['privacy'],'status'=>$site['legal']['approved']?'published':'draft'],
                'consent'=>['essential'=>true,'externalMedia'=>$site['consent']['externalMedia']??true,'analytics'=>$site['consent']['analytics']??false,'marketing'=>$site['consent']['marketing']??false,'bannerText'=>$site['consent']['bannerText']??'','privacyLink'=>$site['consent']['privacyLink']??'/privacy/','version'=>$site['consent']['version']??1,'status'=>'draft'],
                'licenses'=>['text'=>'','status'=>'draft'],
                'seo'=>['titleTemplate'=>'%s · Pianissimo Production','description'=>$site['description'],'canonicalBase'=>null,'ogAssetId'=>null,'robots'=>'noindex, nofollow','sitemap'=>false,'index'=>false],
                'analytics'=>['enabled'=>$site['analytics']['enabled']??false,'provider'=>$site['analytics']['provider']??'none','measurementId'=>$site['analytics']['measurementId']??null,'siteDomain'=>$site['analytics']['siteDomain']??null],
                'appearance'=>['dark'=>'#141414','light'=>'#F3F0EA','accent'=>'#A66A3F'],
            ];
            $bootstrapRail=Setting::find('icon-rail');
            if ($bootstrapRail && !ImportRecord::find('Setting:icon-rail') && collect($bootstrapRail->data['items']??[])->every(fn($item)=>empty($item['destination']))) $bootstrapRail->delete();
            foreach ($settings as $key=>$data) $put(Setting::class,$key,['data'=>$data]);
            return $report;
        });
    }
    private function legacySocialLinks(array $site): array
    {
        $labels=['imdb'=>'IMDb','crewUnited'=>'Crew United','musicHub'=>'Music Hub','spotify'=>'Spotify','tidal'=>'Tidal','appleMusic'=>'Apple Music','deezer'=>'Deezer'];
        $providers=['crewUnited'=>'crew-united','musicHub'=>'music-hub','appleMusic'=>'apple-music']; $links=[]; $order=0;
        foreach (['profileLinks','artistLinks'] as $group) foreach (($site[$group]??[]) as $provider=>$url) if ($url) $links[]=['provider'=>$providers[$provider]??$provider,'label'=>$labels[$provider]??$provider,'url'=>$url,'visible'=>true,'sort_order'=>$order++];
        return $links;
    }
}
