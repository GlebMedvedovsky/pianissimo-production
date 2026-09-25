<?php
namespace App\Services;

use DomainException;
use App\Models\MediaAsset;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

class ContentValidator
{
    public function validate(array $m, bool $release = false, bool $checkFiles = true): void
    {
        // json_decode(..., true) cannot distinguish {} from []; restore known object slots.
        foreach ($m['projects']??[] as $i=>$p) {
            foreach (['memberships','platformLinks','externalLinks'] as $key) if (isset($p[$key])) $m['projects'][$i][$key]=(object)$p[$key];
        }
        foreach ($m['tracks']??[] as $i=>$t) if (isset($t['platformOverrides'])) $m['tracks'][$i]['platformOverrides']=(object)$t['platformOverrides'];
        if (isset($m['site']['artistLinks'])) $m['site']['artistLinks']=(object)$m['site']['artistLinks'];
        if (isset($m['site']['profileLinks'])) $m['site']['profileLinks']=(object)$m['site']['profileLinks'];
        $schema=json_decode(file_get_contents(config('cms.schema')));
        $result=(new Validator())->validate(json_decode(json_encode($m, JSON_THROW_ON_ERROR)), $schema);
        if (!$result->isValid()) {
            $errors=(new ErrorFormatter())->format($result->error());
            throw new DomainException('Manifest-Schema: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
        $fail=fn ($message) => throw new DomainException($message);
        $index=function ($rows, $name) use ($fail) {
            $map=[];
            foreach ($rows as $r) {
                if (isset($map[$r['id']])) $fail("Doppelte ID in $name: {$r['id']}");
                $map[$r['id']]=$r;
            }
            return $map;
        };
        $assets=$index($m['assets'],'Medien'); $tracks=$index($m['tracks'],'Tracks');
        $index($m['projects'],'Projekte'); $sources=$index($m['sources'],'Quellen');
        $slugs=[];
        $ref=function ($id,$types,$owner) use ($assets,$fail) {
            if ($id===null) return;
            if (!isset($assets[$id]) || !in_array($assets[$id]['type'], $types, true)) $fail("$owner: Medium $id fehlt oder hat den falschen Typ.");
        };
        foreach ($m['projects'] as $p) {
            $id=$p['id'];
            if (isset($slugs[$p['slug']])) $fail("Doppelter Slug: {$p['slug']}");
            $slugs[$p['slug']]=true;
            foreach ((array)$p['memberships'] as $member) {
                $ref($member['coverAssetId'],['image'],$id);
                $ref($member['hoverAssetId']??null,['video'],$id);
            }
            foreach ($p['gallery'] as $a) $ref($a,['image'],$id);
            $ref($p['localTrailerAssetId']??null,['video'],$id);
            $ref($p['localTrailerPosterId']??null,['image'],$id);
            if ($id==='kinnhakenhank' && $p['externalFilm']!==null) $fail('Kinnhakenhank: nur der ausgewählte lokale V2-Trailer ist erlaubt.');
            if ($id==='kinnhakenhank' && isset($p['localTrailerAssetId']) && $p['localTrailerAssetId']!=='local-f-87d401c4a58387a5') $fail('Kinnhakenhank: falscher lokaler Trailer; ausschließlich V2 verwenden.');
            if ($id==='letterboxd-everything-everywhere-all-at-once' && ($p['externalFilm']!==null || isset($p['localTrailerAssetId']) || count(array_filter((array)$p['memberships'],fn($v)=>!empty($v['hoverAssetId']))))) $fail('Letterboxd: nur bestätigte Screenshots, kein Video.');
            $seen=[];
            foreach ($p['tracks'] as $t) {
                if (!isset($tracks[$t['trackId']]) || isset($seen[$t['trackId']])) $fail("$id: fehlender oder doppelter Track {$t['trackId']}");
                $seen[$t['trackId']]=true;
            }
            foreach ($p['sourceIds'] as $s) if (!isset($sources[$s])) $fail("$id: fehlende Quelle.");
        }
        foreach ($m['tracks'] as $t) {
            $ref($t['audioAssetId'],['audio'],$t['id']);
            if ($t['peaks']!==null && $t['duration']===null) $fail("{$t['id']}: Waveform benötigt eine Dauer.");
        }
        foreach ($m['site']['mediaSlots'] as $slot=>$values) {
            $ref($values['image'],['image'], $slot); $ref($values['poster'],['image'],$slot); $ref($values['video'],['video'],$slot);
        }
        $ref($m['site']['collective']['logoAssetId']??null,['image'],'Kollektiv-Logo');
        $urls=[$m['site']['productionDomain'],...array_values((array)($m['site']['artistLinks']??[])),...array_values((array)($m['site']['profileLinks']??[])),...array_column($m['site']['socialLinks']??[],'url'),$m['site']['collective']['url']??null];
        foreach (['instagram','spotify','samples'] as $key) $urls[]=$m['site']['contacts'][$key];
        foreach ($m['site']['cv'] as $r) $urls[]=$r['link']??null;
        foreach ($m['projects'] as $p) $urls=[...$urls,...array_values((array)$p['platformLinks']),...array_values((array)($p['externalLinks']??[])),$p['externalFilm']['url']??null];
        foreach ($m['tracks'] as $t) $urls=[...$urls,...array_values((array)$t['platformOverrides'])];
        foreach (array_filter($urls) as $url) if (!in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true)) $fail('Öffentliche Links müssen HTTP(S) verwenden.');
        if ($m['mode']!=='test') {
            foreach ($m['assets'] as $a) if ($a['state']==='test') $fail('Test-Medium im regulären Paket.');
            foreach (array_merge($m['projects'],$m['tracks']) as $r) if ($r['status']==='test') $fail('Test-Datensatz im regulären Paket.');
        }
        if ($checkFiles) foreach ($m['assets'] as $a) {
            $root=realpath(config('cms.media_root'));
            $file=realpath(config('cms.media_root').'/'.substr($a['path'],6));
            if (!$file && str_starts_with($a['path'],'media/cms/')) {
                $managedRoot=realpath(config('cms.managed_media_root'));
                $managedFile=realpath(config('cms.managed_media_root').'/'.substr($a['path'],10));
                if ($managedRoot && $managedFile && str_starts_with($managedFile,$managedRoot.DIRECTORY_SEPARATOR)) { $root=$managedRoot; $file=$managedFile; }
            }
            if (!$file && str_starts_with($a['path'],'media/cms/')) {
                $managed=MediaAsset::where('path',$a['path'])->whereNotNull('storage_path')->first();
                $privateRoot=realpath(config('cms.media_originals_root'));
                $private=$managed?realpath(storage_path('app/private/'.$managed->storage_path)):false;
                if ($privateRoot && $private && str_starts_with($private,$privateRoot.DIRECTORY_SEPARATOR)) { $root=$privateRoot; $file=$private; }
            }
            if (!$file || !$root || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file)) $fail("{$a['id']}: Web-Datei fehlt.");
            if (isset($a['sha256']) && hash_file('sha256',$file)!==$a['sha256']) $fail("{$a['id']}: Prüfsumme stimmt nicht.");
        }
        if ($release) {
            if ($m['mode']!=='release') $fail('Production gesperrt: Paket bleibt prototype.');
            foreach ($m['assets'] as $a) if ($a['state']!=='approved') $fail('Production gesperrt: temporäre Medien.');
            foreach (array_merge($m['projects'],$m['tracks'],$m['site']['cv']) as $r) if ($r['status']!=='published') $fail('Production gesperrt: offene Inhalte.');
            foreach ($m['tracks'] as $t) if (!$t['audioAssetId']) $fail('Production gesperrt: Playback fehlt.');
            if (!$m['site']['legal']['approved'] || !$m['site']['contacts']['email'] || !$m['site']['productionDomain']) $fail('Production gesperrt: Rechtstexte, Kontakt oder Domain fehlen.');
        }
    }
}
