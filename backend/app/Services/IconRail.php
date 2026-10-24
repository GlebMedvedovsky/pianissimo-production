<?php
namespace App\Services;

use DomainException;

class IconRail
{
    public const STYLES=['outline','rounded','filled','accent'];
    public const TYPES=['mail','instagram','imdb','spotify','music-hub','crew-united','tidal','apple-music','samples','deezer','youtube','vimeo','soundcloud','letterboxd','website','other'];
    public const LABELS=[
        'mail'=>'Mail','instagram'=>'Instagram','imdb'=>'IMDb','spotify'=>'Spotify','music-hub'=>'Music Hub',
        'crew-united'=>'Crew United','tidal'=>'Tidal','apple-music'=>'Apple Music','samples'=>'Samples unterm Sofa',
        'deezer'=>'Deezer','youtube'=>'YouTube','vimeo'=>'Vimeo','soundcloud'=>'SoundCloud','letterboxd'=>'Letterboxd',
        'website'=>'Website','other'=>'Weiterer Link',
    ];

    public static function fromManifestSite(array $site): array
    {
        if (isset($site['iconRail']) && is_array($site['iconRail'])) return self::normalize($site['iconRail']);
        $urls=[
            'mail'=>$site['contacts']['email']??null,
            'instagram'=>$site['contacts']['instagram']??null,
            'spotify'=>$site['contacts']['spotify']??($site['artistLinks']['spotify']??null),
            'samples'=>$site['contacts']['samples']??null,
            'imdb'=>$site['profileLinks']['imdb']??null,
            'crew-united'=>$site['profileLinks']['crewUnited']??null,
            'music-hub'=>$site['profileLinks']['musicHub']??null,
            'tidal'=>$site['artistLinks']['tidal']??null,
            'apple-music'=>$site['artistLinks']['appleMusic']??null,
            'deezer'=>$site['artistLinks']['deezer']??null,
        ];
        $visibility=[];$labels=[];
        foreach (($site['socialLinks']??[]) as $link) {
            $type=$link['provider']??null;
            if (!in_array($type,self::TYPES,true)) continue;
            if (empty($urls[$type])) $urls[$type]=$link['url']??null;
            $visibility[$type]=(bool)($link['visible']??true);
            if (!empty($link['label'])) $labels[$type]=(string)$link['label'];
        }
        $items=[];
        foreach (self::TYPES as $order=>$type) $items[]=[
            'type'=>$type,'label'=>$labels[$type]??self::LABELS[$type],'destination'=>$urls[$type]??null,
            'visible'=>!empty($urls[$type]) && ($visibility[$type]??true),'sort_order'=>$order,
        ];
        return ['style'=>'outline','items'=>$items];
    }

    public static function normalize(array $rail): array
    {
        $style=in_array($rail['style']??null,self::STYLES,true)?$rail['style']:'outline';
        $byType=[];
        foreach (($rail['items']??[]) as $item) {
            $type=$item['type']??null;
            if (!in_array($type,self::TYPES,true) || isset($byType[$type])) continue;
            $byType[$type]=[
                'type'=>$type,
                'label'=>(string)($item['label']??self::LABELS[$type]),
                'destination'=>($item['destination']??null) ?: null,
                'visible'=>(bool)($item['visible']??false),
                'sort_order'=>(int)($item['sort_order']??array_search($type,self::TYPES,true)),
            ];
        }
        foreach (self::TYPES as $order=>$type) if (!isset($byType[$type])) $byType[$type]=[
            'type'=>$type,'label'=>self::LABELS[$type],'destination'=>null,'visible'=>false,'sort_order'=>$order,
        ];
        $items=array_values($byType);
        usort($items,fn($a,$b)=>$a['sort_order']<=>$b['sort_order'] ?: array_search($a['type'],self::TYPES,true)<=>array_search($b['type'],self::TYPES,true));
        foreach ($items as $order=>&$item) $item['sort_order']=$order;
        unset($item);
        return compact('style','items');
    }

    public static function validate(array $rail): array
    {
        if (!in_array($rail['style']??null,self::STYLES,true)) throw new DomainException('Ungültiger Symbolstil.');
        $rawTypes=array_column($rail['items']??[],'type');
        if (array_diff($rawTypes,self::TYPES)) throw new DomainException('Unbekannter Symboltyp.');
        if (count($rawTypes)!==count(array_unique($rawTypes))) throw new DomainException('Jeder Symboltyp darf nur einmal vorkommen.');
        $rail=self::normalize($rail);
        $seen=[];
        foreach ($rail['items'] as $item) {
            if (isset($seen[$item['type']])) throw new DomainException('Jeder Symboltyp darf nur einmal vorkommen.');
            $seen[$item['type']]=true;
            if ($item['label']==='' || mb_strlen($item['label'])>100) throw new DomainException('Symbolbezeichnungen müssen 1 bis 100 Zeichen lang sein.');
            $destination=$item['destination'];
            if ($item['visible'] && !$destination) throw new DomainException("{$item['label']}: Ein sichtbares Symbol benötigt ein Ziel.");
            if (!$destination) continue;
            if ($item['type']==='mail') {
                if (filter_var($destination,FILTER_VALIDATE_EMAIL)===false) throw new DomainException('Mail: Bitte eine gültige E-Mail-Adresse eingeben.');
                continue;
            }
            $scheme=strtolower((string)(parse_url($destination,PHP_URL_SCHEME)??''));
            if (filter_var($destination,FILTER_VALIDATE_URL)===false || !in_array($scheme,['http','https'],true)) throw new DomainException("{$item['label']}: Nur HTTP(S)-Links sind erlaubt.");
        }
        return $rail;
    }
}
