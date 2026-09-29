<?php
namespace App\Services;

use App\Models\Publication;
use DomainException;
use Illuminate\Support\Facades\DB;

class DraftStateSnapshot
{
    private const TABLES=['media_assets','projects','tracks','memberships','project_media','project_tracks','cv_entries','settings','external_services','import_records'];
    private const DELETE_ORDER=['project_tracks','project_media','memberships','projects','tracks','cv_entries','external_services','settings','import_records','media_assets'];
    private const GROUPS=[
        'Projekte und Zuordnungen'=>['projects','memberships','project_media','project_tracks'],
        'Tracks'=>['tracks'],
        'Medienbibliothek'=>['media_assets'],
        'CV-Einträge'=>['cv_entries'],
        'Website-Einstellungen'=>['settings'],
        'Externe Dienste'=>['external_services'],
    ];

    public function capture(string $candidateHash): array
    {
        $tables=[];
        foreach(self::TABLES as $table)$tables[$table]=DB::table($table)->orderBy($this->orderColumn($table))->get()->map(fn($row)=>(array)$row)->all();
        return ['version'=>1,'candidateHash'=>$candidateHash,'tables'=>$tables];
    }

    public function promote(string $file,string $expectedSha256,string $manifestHash): void
    {
        if(!is_file($file)||hash_file('sha256',$file)!==$expectedSha256)throw new DomainException('Der gespeicherte CMS-Stand ist ungültig.');
        $snapshot=$this->decode($file);$snapshot['manifestHash']=$manifestHash;
        $target=config('cms.frontend_apply_state').'/applied-cms.json';$this->writeApplied($target,$snapshot);
    }

    private function writeApplied(string $target,array $snapshot): void
    {
        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0750,true)&&!is_dir(dirname($target)))throw new DomainException('CMS-Stand konnte nicht gespeichert werden.');
        $bytes=json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        $pending=$target.'.pending';
        if(file_put_contents($pending,$bytes,LOCK_EX)===false||!rename($pending,$target))throw new DomainException('CMS-Stand konnte nicht atomar gespeichert werden.');
    }

    public function preview(): array
    {
        $snapshot=$this->applied();$current=$this->capture($snapshot['candidateHash']);$descriptions=[];
        foreach(self::GROUPS as $label=>$tables)if(!$this->same($snapshot['tables'],$current['tables'],$tables))$descriptions[]=$label.' werden auf den zuletzt übernommenen Stand zurückgesetzt.';
        return ['candidateHash'=>$snapshot['candidateHash'],'manifestHash'=>$snapshot['manifestHash']??null,'descriptions'=>$descriptions];
    }

    public function hasChanges(): bool
    {
        try{return $this->preview()['descriptions']!==[];}catch(\Throwable){return false;}
    }

    public function markCandidatesStale(array $hashes): int
    {
        $hashes=array_values(array_unique(array_filter($hashes)));if($hashes===[])return 0;
        $count=Publication::whereIn('hash',$hashes)->where('status','candidate')->update(['status'=>'stale','updated_at'=>now()]);$this->invalidatePlans($hashes);return $count;
    }

    public function discard(int $userId): array
    {
        return $this->withLock(fn()=>$this->discardLocked($userId));
    }

    private function discardLocked(int $userId): array
    {
        $snapshot=$this->applied();$review=$this->preview();
        if($review['descriptions']===[])throw new DomainException('Es gibt keine nicht veröffentlichten Änderungen.');
        $frontendHash=hash_file('sha256',config('cms.manifest'));
        [$stale,$hashes]=DB::transaction(function() use($snapshot,$review,$userId){
            foreach(self::DELETE_ORDER as $table)DB::table($table)->delete();
            foreach(self::TABLES as $table)foreach(array_chunk($snapshot['tables'][$table]??[],100) as $rows)if($rows)DB::table($table)->insert($rows);
            $hashes=Publication::where('status','candidate')->pluck('hash')->unique()->all();
            $count=Publication::where('status','candidate')->update(['status'=>'stale','updated_at'=>now()]);
            app(AuditLogger::class)->record('publication',$review['candidateHash'],'draft-discard',null,['discarded'=>$review['descriptions'],'staleCandidates'=>$count,'adminUserId'=>$userId]);
            return [$count,$hashes];
        });
        $this->invalidatePlans($hashes);
        app(DraftPreviewService::class)->invalidateForUser($userId);
        if(hash_file('sha256',config('cms.manifest'))!==$frontendHash)throw new DomainException('Der Frontend-Stand wurde unerwartet verändert.');
        return [...$review,'staleCandidates'=>$stale];
    }

    private function withLock(callable $callback): mixed
    {
        $state=config('cms.frontend_apply_state');if(!is_dir($state)&&!mkdir($state,0750,true)&&!is_dir($state))throw new DomainException('Apply-Statusverzeichnis konnte nicht erstellt werden.');
        $lock=$state.'/operation.lock';$handle=@fopen($lock,'x');if(!$handle)throw new DomainException('Eine andere Veröffentlichung läuft bereits oder wurde unterbrochen.');
        fwrite($handle,(string)getmypid());try{return $callback();}finally{fclose($handle);@unlink($lock);}
    }

    private function applied(): array
    {
        $file=config('cms.frontend_apply_state').'/applied-cms.json';
        if(!is_file($file))$this->initializeFromActiveManifest($file);
        if(!is_file($file))throw new DomainException('Noch kein wiederherstellbarer CMS-Stand vorhanden. Bitte Änderungen zuerst einmal übernehmen.');
        return $this->decode($file);
    }

    private function initializeFromActiveManifest(string $file): void
    {
        $manifestFile=config('cms.manifest');if(!is_file($manifestFile))return;$manifest=json_decode(file_get_contents($manifestFile),true,512,JSON_THROW_ON_ERROR);app(ContentValidator::class)->validate($manifest);
        $hash=hash_file('sha256',$manifestFile);$current=$this->capture($hash);$imported=null;
        DB::beginTransaction();
        try{
            foreach(self::DELETE_ORDER as $table)DB::table($table)->delete();
            app(ManifestImporter::class)->import($manifest);$imported=$this->capture($hash);
        }finally{DB::rollBack();}
        if(!$imported)throw new DomainException('Der aktive Frontend-Stand konnte nicht gelesen werden.');
        $imported['tables']['media_assets']=$this->preserveMediaDetails($imported['tables']['media_assets'],$current['tables']['media_assets']);
        $imported['tables']['settings']=$this->mergeAppliedSettings($imported['tables']['settings'],$current['tables']['settings'],$manifest);
        $imported['tables']['external_services']=$current['tables']['external_services'];
        $imported['manifestHash']=$hash;$this->writeApplied($file,$imported);
    }

    private function preserveMediaDetails(array $applied,array $current): array
    {
        $current=collect($current)->keyBy('id');$private=['storage_path','original_name','mime_type','file_size','purpose','caption','license','source','rights_scope','created_at','updated_at'];
        foreach($applied as &$row)if($old=$current->get($row['id']))foreach($private as $field)if(array_key_exists($field,$old))$row[$field]=$old[$field];unset($row);return $applied;
    }

    private function mergeAppliedSettings(array $applied,array $current,array $manifest): array
    {
        $applied=collect($applied)->keyBy('id');$current=collect($current)->keyBy('id');
        $fields=['site'=>['title','description','productionDomain','language'],'home'=>['media'],'music'=>['media'],'directing'=>['media'],'about'=>['biography','media','collective'],'contact'=>array_keys($manifest['site']['contacts']??[]),'social'=>['artistLinks','profileLinks','links'],'impressum'=>['text','status'],'privacy'=>['text','status'],'seo'=>['description']];
        if(isset($manifest['site']['consent']))$fields['consent']=['essential','externalMedia','analytics','marketing','bannerText','privacyLink','version'];
        if(isset($manifest['site']['analytics']))$fields['analytics']=['enabled','provider','measurementId','siteDomain'];
        foreach($fields as $id=>$keys){$old=$current->get($id);$next=$applied->get($id);if(!$next)continue;$oldData=json_decode($old['data']??'[]',true)?:[];$nextData=json_decode($next['data']??'[]',true)?:[];foreach($keys as $key)if(array_key_exists($key,$nextData))$oldData[$key]=$nextData[$key];$next['data']=json_encode($oldData,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$next['created_at']=$old['created_at']??$next['created_at'];$next['updated_at']=$old['updated_at']??$next['updated_at'];$applied[$id]=$next;}
        foreach($current as $id=>$row)if(!$applied->has($id))$applied[$id]=$row;return $applied->values()->all();
    }

    private function invalidatePlans(array $candidateHashes): void
    {
        $folder=config('cms.frontend_apply_state').'/plans';if(!is_dir($folder)||$candidateHashes===[])return;
        foreach(glob($folder.'/*.json')?:[] as $file){$plan=json_decode(file_get_contents($file),true);if(!in_array($plan['candidateHash']??null,$candidateHashes,true))continue;$marker=substr($file,0,-5).'.stale';if(file_put_contents($marker,"discarded\n",LOCK_EX)===false)throw new DomainException('Alte Prüfungen konnten nicht sicher entwertet werden.');}
    }

    private function decode(string $file): array
    {
        $value=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($value['version']??null)!==1||!is_array($value['tables']??null))throw new DomainException('Der gespeicherte CMS-Stand ist ungültig.');
        foreach(self::TABLES as $table)if(!is_array($value['tables'][$table]??null))throw new DomainException('Der gespeicherte CMS-Stand ist unvollständig.');
        return $value;
    }

    private function same(array $a,array $b,array $tables): bool
    {
        foreach($tables as $table)if($this->canonicalRows($a[$table]??[])!==$this->canonicalRows($b[$table]??[]))return false;
        return true;
    }

    private function canonicalRows(array $rows): array
    {
        foreach($rows as &$row){unset($row['created_at'],$row['updated_at']);ksort($row);}unset($row);
        usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));return $rows;
    }

    private function orderColumn(string $table): string{return in_array($table,['memberships','project_media','project_tracks','external_services'],true)?'id':(in_array($table,['settings','media_assets','projects','tracks','cv_entries','import_records'],true)?'id':'id');}
}
