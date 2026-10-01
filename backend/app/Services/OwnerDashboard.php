<?php
namespace App\Services;

use App\Filament\Pages\{About,Analytics,Consent,Publication,Seo,Social};
use App\Filament\Resources\{CvEntryResource,MediaAssetResource,ProjectResource,TrackResource};
use App\Models\{AuditEvent,CvEntry,MediaAsset,Membership,Project,ProjectMedia,ProjectTrack,Setting,Track};

class OwnerDashboard
{
    public function data(): array
    {
        $lastApply=AuditEvent::where('object_type','publication')->where('action','apply')->latest('id')->first();
        $lastChange=collect([Project::class,Track::class,MediaAsset::class,CvEntry::class,Membership::class,ProjectMedia::class,ProjectTrack::class,Setting::class])
            ->map(fn($model)=>$model::max('updated_at'))->filter()->max();
        $lastChange=$lastChange?\Illuminate\Support\Carbon::parse($lastChange):null;
        $hasDraftChanges=app(DraftStateSnapshot::class)->hasChanges()||!$lastApply||($lastChange&&$lastApply->created_at&&$lastChange>$lastApply->created_at);
        $review=app(FrontendPublication::class)->currentReview();
        $seo=Setting::read('seo'); $consent=Setting::read('consent'); $analytics=Setting::read('analytics');
        $hints=[];
        $missingAlt=MediaAsset::where('type','image')->where(fn($query)=>$query->whereNull('alt')->orWhere('alt',''))->count();
        if($missingAlt)$hints[]=$this->hint('alt','Alternativtexte ergänzen',"{$missingAlt} Bilder haben noch keinen Alternativtext. Das erschwert die Nutzung mit Screenreadern.",MediaAssetResource::getUrl('index'));
        if(str_starts_with((string)($seo['robots']??''),'noindex'))$hints[]=$this->hint('noindex','Suchmaschinen sind noch ausgeschlossen','Vor einer späteren öffentlichen Freigabe sollte geprüft werden, ob die Website indexiert werden darf.',Seo::getUrl());
        if(empty($seo['description']))$hints[]=$this->hint('meta','Meta-Beschreibung fehlt','Eine kurze Standardbeschreibung hilft bei Suchergebnissen und beim Teilen.',Seo::getUrl());
        if(empty($seo['canonicalBase']))$hints[]=$this->hint('canonical','Hauptadresse fehlt','Die bevorzugte öffentliche Website-Adresse ist noch nicht eingetragen.',Seo::getUrl());
        if(empty($seo['ogAssetId']))$hints[]=$this->hint('og','Standard-Vorschaubild fehlt','Beim Teilen in Messengern und sozialen Netzwerken fehlt ein festgelegtes Standardbild.',Seo::getUrl());
        if(($consent['status']??'draft')!=='published')$hints[]=$this->hint('consent','Cookie-Einstellungen noch nicht freigegeben','Die Einstellungen sind noch als Entwurf oder Prüfung markiert.',Consent::getUrl());
        if(($analytics['provider']??'none')==='none' || !($analytics['enabled']??false))$hints[]=$this->hint('analytics','Analytics ist nicht eingerichtet','Derzeit werden keine Besucherstatistiken erhoben.',Analytics::getUrl());
        if($review['kind']==='stale')$hints[]=$this->hint('review-stale','Geprüfte Änderungen sind nicht mehr aktuell','Der Entwurf wurde nach der Prüfung geändert. Bitte prüfen Sie die Änderungen erneut.',Publication::getUrl());
        try{$packageMedia=count(app(ManifestExporter::class)->package(checkFiles:false)['manifest']['assets']);}catch(\Throwable){$packageMedia=null;}
        return [
            'status'=>['hasDraftChanges'=>$hasDraftChanges,'lastApply'=>$lastApply?->created_at,'review'=>$review['kind'],'productionConnected'=>false],
            'counts'=>['projects'=>Project::count(),'tracks'=>Track::count(),'media'=>MediaAsset::count(),'packageMedia'=>$packageMedia,'cv'=>CvEntry::count()],
            'links'=>['publication'=>Publication::getUrl(),'preview'=>route('draft-preview.open'),'projectIndex'=>ProjectResource::getUrl('index'),'projectCreate'=>ProjectResource::getUrl('create'),'trackIndex'=>TrackResource::getUrl('index'),'mediaIndex'=>MediaAssetResource::getUrl('index'),'mediaCreate'=>MediaAssetResource::getUrl('create'),'cvIndex'=>CvEntryResource::getUrl('index'),'about'=>About::getUrl(),'social'=>Social::getUrl(),'seo'=>Seo::getUrl(),'analytics'=>Analytics::getUrl()],
            'seo'=>['indexing'=>str_starts_with((string)($seo['robots']??''),'index')?'Freigegeben':'Ausgeschlossen','description'=>empty($seo['description'])?'Fehlt':'Vorhanden','canonical'=>empty($seo['canonicalBase'])?'Fehlt':'Vorhanden','og'=>empty($seo['ogAssetId'])?'Fehlt':'Vorhanden'],
            'analytics'=>['provider'=>match($analytics['provider']??'none'){'ga4'=>'Google Analytics 4','plausible'=>'Plausible',default=>'Nicht eingerichtet'},'active'=>(bool)($analytics['enabled']??false)],
            'hints'=>$hints,
        ];
    }

    private function hint(string $id,string $title,string $body,string $url): array { return compact('id','title','body','url'); }
}
