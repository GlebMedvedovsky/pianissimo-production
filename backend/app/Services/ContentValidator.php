<?php
namespace App\Services;

use DomainException;
use App\Models\MediaAsset;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

class ContentValidator
{
    public const POLICY_PUBLICATION='publication';
    public const POLICY_DRAFT_PREVIEW='draft-preview';
    public const KINNHAKENHANK_TRAILER_ASSET_ID='local-f-87d401c4a58387a5';
    public const KINNHAKENHANK_TRAILER_SHA256='fab8ab6335ef8c48b9c070b434f915c5f62a301570ee510e8bc11165f2803252';

    /**
     * Restore JSON object semantics lost by json_decode(..., true).
     *
     * Only empty lists at schema-declared object positions are ambiguous. Non-empty
     * lists are preserved so the schema validator can reject genuine type errors.
     */
    public function normalizeJsonObjectSlots(array $manifest): array
    {
        return $this->normalizeJsonObjectSlotsAgainstSchema($manifest,$this->schema());
    }

    private function normalizeJsonObjectSlotsAgainstSchema(array $manifest, object $schema): array
    {
        $normalized=$this->normalizeJsonValue($manifest,$schema);

        return is_array($normalized)?$normalized:$manifest;
    }

    private function normalizeJsonValue(mixed $value, object $schema): mixed
    {
        $schema=$this->matchingSchema($schema,$value);
        $types=(array)($schema->type??[]);
        if(in_array('object',$types,true)){
            $properties=$schema->properties??null;
            if(is_array($value)){
                if(array_is_list($value))return $value===[]?(object)[]:$value;
                foreach($value as $key=>$item){
                    $propertySchema=is_object($properties)?($properties->{$key}??null):null;
                    if(is_object($propertySchema))$value[$key]=$this->normalizeJsonValue($item,$propertySchema);
                }
            }elseif(is_object($value)){
                foreach(get_object_vars($value) as $key=>$item){
                    $propertySchema=is_object($properties)?($properties->{$key}??null):null;
                    if(is_object($propertySchema))$value->{$key}=$this->normalizeJsonValue($item,$propertySchema);
                }
            }

            return $value;
        }
        if(in_array('array',$types,true) && is_array($value) && is_object($schema->items??null)){
            foreach($value as $key=>$item)$value[$key]=$this->normalizeJsonValue($item,$schema->items);
        }

        return $value;
    }

    private function matchingSchema(object $schema, mixed $value): object
    {
        if(!is_array($schema->anyOf??null))return $schema;
        foreach($schema->anyOf as $candidate){
            if(!is_object($candidate))continue;
            $types=(array)($candidate->type??[]);
            if($value===null && in_array('null',$types,true))return $candidate;
            if(in_array('object',$types,true) && (is_object($value) || (is_array($value) && ($value===[] || !array_is_list($value)))))return $candidate;
            if(in_array('array',$types,true) && is_array($value))return $candidate;
        }

        return $schema;
    }

    private function schema(): object
    {
        $contents=file_get_contents(config('cms.schema'));
        if($contents===false)throw new DomainException('Manifest-Schema konnte nicht gelesen werden.');
        $schema=json_decode($contents,false,512,JSON_THROW_ON_ERROR);
        if(!is_object($schema))throw new DomainException('Manifest-Schema ist ungültig.');

        return $schema;
    }

    public function validate(array $m, bool $release = false, bool $checkFiles = true, string $policy = self::POLICY_PUBLICATION): void
    {
        if (!in_array($policy,[self::POLICY_PUBLICATION,self::POLICY_DRAFT_PREVIEW],true)) throw new DomainException('Unbekannte Validierungsrichtlinie.');
        $schema=$this->schema();
        $normalized=$this->normalizeJsonObjectSlotsAgainstSchema($m,$schema);
        $result=(new Validator())->validate(json_decode(json_encode($normalized, JSON_THROW_ON_ERROR)), $schema);
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
            if ($id==='kinnhakenhank' && isset($p['localTrailerAssetId']) && $p['localTrailerAssetId']!==self::KINNHAKENHANK_TRAILER_ASSET_ID) $fail('Kinnhakenhank: falscher lokaler Trailer; ausschließlich V2 verwenden.');
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
        if ($policy===self::POLICY_PUBLICATION && $m['mode']!=='test') {
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
                try {$private=$managed?app(MediaStorage::class)->resolveStored($managed->storage_path):false;} catch (DomainException) {$private=false;}
                if ($private) { $root=realpath(config('cms.media_originals_root')); $file=$private; }
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
            $kinnhakenhank=collect($m['projects'])->firstWhere('id','kinnhakenhank');
            if ($kinnhakenhank) {
                $trailerId=$kinnhakenhank['localTrailerAssetId']??null;
                if ($trailerId!==self::KINNHAKENHANK_TRAILER_ASSET_ID) $fail('Kinnhakenhank: Production erfordert exakt den freigegebenen V2-Trailer.');
                $trailer=$assets[self::KINNHAKENHANK_TRAILER_ASSET_ID]??null;
                if (!is_array($trailer) || ($trailer['sha256']??null)!==self::KINNHAKENHANK_TRAILER_SHA256) $fail('Kinnhakenhank: Prüfsumme des freigegebenen V2-Trailers stimmt nicht.');
                $videoReferences=[$trailerId];
                foreach ((array)$kinnhakenhank['memberships'] as $member) if (!empty($member['hoverAssetId'])) $videoReferences[]=$member['hoverAssetId'];
                foreach (array_filter($videoReferences) as $videoId) if ($videoId!==self::KINNHAKENHANK_TRAILER_ASSET_ID) $fail('Kinnhakenhank: nicht freigegebene Video-Referenz im Production-Paket.');
                if ($kinnhakenhank['externalFilm']!==null) $fail('Kinnhakenhank: externe Filme sind im Production-Paket verboten.');
            }
        }
    }
}
