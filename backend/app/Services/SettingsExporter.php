<?php
namespace App\Services;
use App\Models\{Setting,Project,MediaAsset,ExternalService};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use DomainException;

/** Extension contains only fields absent from manifest 1.0.0. Never a second content source. */
class SettingsExporter
{
    public function export(): array
    {
        $take=fn($key,$fields)=>Arr::only(Setting::read($key),$fields);
        $seo=$take('seo',['titleTemplate','description','canonicalBase','ogAssetId','robots','sitemap','index']);
        $consent=$take('consent',['essential','externalMedia','analytics','marketing','bannerText','privacyLink','version','status']);
        $home=$take('home',['loop','autoplayMuted']);
        $analytics=$take('analytics',['enabled','provider','measurementId','siteDomain']);
        $consent['version']=(int)($consent['version']??1); $analytics['siteDomain']=$analytics['siteDomain']??null;
        Validator::make(['seo'=>$seo,'consent'=>$consent,'home'=>$home,'analytics'=>$analytics],[
            'seo.titleTemplate'=>'required|string|max:255','seo.description'=>'nullable|string|max:2000',
            'seo.canonicalBase'=>'nullable|url:http,https','seo.robots'=>['required',\Illuminate\Validation\Rule::in(['noindex, nofollow','index, follow','noindex, follow'])],
            'seo.sitemap'=>'required|boolean','seo.index'=>'required|boolean','seo.ogAssetId'=>'nullable|string',
            'consent.essential'=>'required|accepted','consent.externalMedia'=>'required|accepted',
            'consent.analytics'=>'required|boolean','consent.marketing'=>'required|boolean',
            'consent.bannerText'=>'nullable|string','consent.privacyLink'=>'required|regex:#^/[a-z0-9/-]+$#',
            'consent.version'=>'required|integer|min:1',
            'consent.status'=>'required|in:draft,reviewed,published',
            'home.loop'=>'required|accepted','home.autoplayMuted'=>'required|accepted',
            'analytics.enabled'=>'required|boolean','analytics.provider'=>'required|in:none,ga4,plausible',
            'analytics.measurementId'=>'nullable|regex:/^G-[A-Z0-9]{4,20}$/','analytics.siteDomain'=>'nullable|regex:/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/',
        ])->validate();
        if (!empty($seo['ogAssetId']) && !MediaAsset::whereKey($seo['ogAssetId'])->where('type','image')->exists()) throw new DomainException('SEO: OG-Bild fehlt oder ist kein Bild.');
        if ($analytics['enabled'] && (!$consent['analytics'] || $analytics['provider']==='none')) throw new DomainException('Analytics benötigt expliziten Consent und einen konfigurierten Anbieter.');
        if ($analytics['enabled'] && $analytics['provider']==='ga4' && empty($analytics['measurementId'])) throw new DomainException('Google Analytics benötigt eine gültige Measurement-ID.');
        if ($analytics['enabled'] && $analytics['provider']==='plausible' && empty($analytics['siteDomain'])) throw new DomainException('Plausible benötigt eine gültige Website-Domain.');
        $services=[];
        foreach (ExternalService::orderBy('sort_order')->orderBy('id')->get() as $s) {
            $v=$s->only(['name','provider','category','purpose','description','privacy_url','storage_description','requires_consent','enabled','provider_id','sort_order']);
            Validator::make($v,[
                'name'=>'required|string|max:255','provider'=>'required|in:youtube,vimeo,spotify,tidal,deezer,appleMusic,ga4,plausible',
                'category'=>'required|in:externalMedia,analytics,marketing','purpose'=>'required|string',
                'privacy_url'=>'required|url:http,https','requires_consent'=>'required|accepted','enabled'=>'required|boolean',
                'provider_id'=>'nullable|regex:/^[A-Za-z0-9._-]{1,100}$/','sort_order'=>'required|integer',
            ])->validate();
            $services[]=$v;
        }
        $projectSeo=[]; $additionalMedia=[];
        foreach (Project::where('status','published')->orderBy('id')->with('mediaAssignments')->get() as $p) {
            $value=Arr::only($p->seo??[],['title','description','canonical','ogAssetId','noindex']);
            Validator::make($value,['title'=>'nullable|string|max:255','description'=>'nullable|string|max:2000','canonical'=>'nullable|url:http,https','noindex'=>'nullable|boolean','ogAssetId'=>'nullable|string'])->validate();
            if (!empty($value['ogAssetId']) && !MediaAsset::whereKey($value['ogAssetId'])->where('type','image')->exists()) throw new DomainException("{$p->id}: SEO-Bild fehlt.");
            $projectSeo[$p->id]=(object)$value;
            $additionalMedia[$p->id]=$p->mediaAssignments->whereIn('slot',['soundtrack_cover','other'])->map->only(['media_asset_id','slot','sort_order'])->values()->all();
        }
        $appearance=$take('appearance',['dark','light','accent']);
        Validator::make($appearance,['dark'=>'required|hex_color','light'=>'required|hex_color','accent'=>'required|hex_color'])->validate();
        $about=$take('about',['title','visible','theme','getInTouch']);
        Validator::make($about,['title'=>'required|string','visible'=>'required|boolean','theme'=>'required|in:follow-section,dark,light','getInTouch'=>'nullable|string'])->validate();
        $licenses=$take('licenses',['text','status']);
        Validator::make($licenses,['text'=>'nullable|string','status'=>'required|in:draft,reviewed,published'])->validate();
        return [
            'schemaVersion'=>'cms-settings-1.0.0','scope'=>'next_draft','deploymentAuthorized'=>false,
            'home'=>$home,'about'=>$about,'appearance'=>$appearance,
            'navigation'=>['music'=>(string)(Setting::read('music')['label']??'Film Music'),'directing'=>(string)(Setting::read('directing')['label']??'Directing')],
            'footer'=>(string)(Setting::read('contact')['footer']??''),'legalIdentity'=>(string)(Setting::read('contact')['legalIdentity']??''),
            'seo'=>$seo,'projectSeo'=>(object)$projectSeo,'projectMedia'=>(object)$additionalMedia,
            'consent'=>$consent,'externalServices'=>$services,'analytics'=>$analytics,
            'licenses'=>$licenses,
        ];
    }
}
