<?php
namespace App\Services;

use App\Filament\Pages\{About,Directing,Home,Music,Seo};
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TrackResource;
use App\Models\{MediaAsset,Membership,Project,ProjectMedia,Setting,Track};
use DomainException;
use Illuminate\Support\Facades\{DB,Storage};

class MediaUsageResolver
{
    public function usages(MediaAsset $asset): array
    {
        $rows=[];
        foreach (Membership::with('project')->where('cover_asset_id',$asset->id)->get() as $r) $rows[]=$this->row('membership:'.$r->id.':cover','Projekt „'.$r->project->title.'“','Cover',true,'image',ProjectResource::getUrl('edit',['record'=>$r->project_id]));
        foreach (Membership::with('project')->where('hover_asset_id',$asset->id)->get() as $r) $rows[]=$this->row('membership:'.$r->id.':hover','Projekt „'.$r->project->title.'“','Hover-Medium',false,'video',ProjectResource::getUrl('edit',['record'=>$r->project_id]));
        foreach (ProjectMedia::with('project')->where('media_asset_id',$asset->id)->get() as $r) {
            $labels=['gallery'=>'Galerie','trailer'=>'Trailer','trailer_poster'=>'Trailer-Poster','soundtrack_cover'=>'Soundtrack-Cover','other'=>'Weiteres Medium'];
            $types=['trailer'=>'video','gallery'=>'image','trailer_poster'=>'image','soundtrack_cover'=>'image'];
            $rows[]=$this->row('project_media:'.$r->id,'Projekt „'.$r->project->title.'“',$labels[$r->slot]??$r->slot,false,$types[$r->slot]??$asset->type,ProjectResource::getUrl('edit',['record'=>$r->project_id]));
        }
        foreach (Track::where('audio_asset_id',$asset->id)->get() as $r) $rows[]=$this->row('track:'.$r->id,'Track „'.$r->title.'“','Playback',false,'audio',TrackResource::getUrl('edit',['record'=>$r->id]));
        foreach (Project::where('seo','like','%"ogAssetId":"'.$asset->id.'"%')->get() as $r) if (($r->seo['ogAssetId']??null)===$asset->id) $rows[]=$this->row('project_seo:'.$r->id,'Projekt „'.$r->title.'“','SEO-Bild',false,'image',ProjectResource::getUrl('edit',['record'=>$r->id]));
        $settings=[
            ['home','media.image','Startseite','Bild','image',Home::getUrl()],['home','media.video','Startseite','Video','video',Home::getUrl()],['home','media.poster','Startseite','Poster','image',Home::getUrl()],
            ['music','media.image','Film Music','Bild','image',Music::getUrl()],['music','media.video','Film Music','Video','video',Music::getUrl()],['music','media.poster','Film Music','Poster','image',Music::getUrl()],
            ['directing','media.image','Directing','Bild','image',Directing::getUrl()],['directing','media.video','Directing','Video','video',Directing::getUrl()],['directing','media.poster','Directing','Poster','image',Directing::getUrl()],
            ['about','media.image','About','Bild','image',About::getUrl()],['about','media.video','About','Video','video',About::getUrl()],['about','media.poster','About','Poster','image',About::getUrl()],
            ['about','collective.logoAssetId','About','Kollektiv-Logo','image',About::getUrl()],['seo','ogAssetId','SEO','Standard-OG-Bild','image',Seo::getUrl()],
        ];
        foreach ($settings as [$setting,$path,$location,$slot,$type,$url]) if (data_get(Setting::read($setting),$path)===$asset->id) {
            $field=str_starts_with($path,'media.')?substr($path,6):null;
            $active=$field?MediaSlotPolicy::isActive($setting,$field,Setting::read($setting)['media']??[]):true;
            $rows[]=$this->row('setting:'.$setting.':'.$path,$location,$slot,false,$type,$url,$active);
        }
        return $rows;
    }

    private function row(string $key,string $location,string $slot,bool $required,string $type,string $url,bool $active=true): array
    { return compact('key','location','slot','required','type','url','active'); }

    public function detach(MediaAsset $asset,array $keys): void
    {
        DB::transaction(function() use($asset,$keys) {
            $index=collect($this->usages($asset))->keyBy('key');
            foreach ($keys as $key) {
                $usage=$index->get($key); if (!$usage) throw new DomainException('Eine ausgewählte Verwendung ist nicht mehr aktuell.');
                if ($usage['required']) throw new DomainException($usage['location'].' → '.$usage['slot'].': Ersatz erforderlich.');
            }
            foreach ($keys as $key) $this->write($key,null,true);
            app(AuditLogger::class)->record('media',$asset->id,'detach',null,['count'=>count($keys)]);
        });
    }

    public function replace(MediaAsset $asset,MediaAsset $replacement,array $keys,bool $deleteAfter=false): void
    {
        DB::transaction(function() use($asset,$replacement,$keys,$deleteAfter) {
            $all=$this->usages($asset); $index=collect($all)->keyBy('key');
            if ($deleteAfter && count($keys)!==count($all)) throw new DomainException('Zum Löschen müssen alle Verwendungen ausgewählt und ersetzt werden.');
            foreach ($keys as $key) {
                $usage=$index->get($key); if (!$usage) throw new DomainException('Eine ausgewählte Verwendung ist nicht mehr aktuell.');
                if (!MediaTypePolicy::isCompatible($replacement,$usage['type'])) throw new DomainException($usage['location'].' → '.$usage['slot'].': inkompatibler Medientyp.');
            }
            foreach ($keys as $key) $this->write($key,$replacement->id,false);
            if ($deleteAfter) $this->deleteUnused($asset);
            app(AuditLogger::class)->record('media',$asset->id,$deleteAfter?'replace-all-delete':'bulk-replace',null,['replacement'=>$replacement->id,'count'=>count($keys)]);
        });
    }

    public function deleteUnused(MediaAsset $asset): void
    {
        if ($this->usages($asset)) throw new DomainException('Die Datei wird noch verwendet und kann nicht gelöscht werden.');
        $path=$asset->storage_path; $asset->delete();
        if ($path) Storage::disk('local')->delete($path);
        app(AuditLogger::class)->record('media',$asset->id,'delete',$asset->sha256);
    }

    private function write(string $key,?string $replacement,bool $detach): void
    {
        $parts=explode(':',$key); $kind=array_shift($parts);
        if ($kind==='membership') { $row=Membership::findOrFail((int)$parts[0]); $row->update([$parts[1]==='cover'?'cover_asset_id':'hover_asset_id'=>$replacement]); return; }
        if ($kind==='project_media') { $row=ProjectMedia::findOrFail((int)$parts[0]); $detach?$row->delete():$row->update(['media_asset_id'=>$replacement]); return; }
        if ($kind==='track') { Track::findOrFail($parts[0])->update(['audio_asset_id'=>$replacement]); return; }
        if ($kind==='project_seo') { $row=Project::findOrFail($parts[0]); $seo=$row->seo??[]; $seo['ogAssetId']=$replacement; $row->update(['seo'=>$seo]); return; }
        if ($kind==='setting') { $setting=array_shift($parts); $row=Setting::read($setting); data_set($row,implode(':',$parts),$replacement); Setting::put($setting,$row); return; }
        throw new DomainException('Unbekannte Medienverwendung.');
    }
}
