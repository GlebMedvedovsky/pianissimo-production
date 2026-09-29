<?php
namespace App\Services;

use DomainException;
use Illuminate\Support\Arr;

class FrontendPublication
{
    private function hex(string $value): string { if (!preg_match('/^[a-f0-9]{64}$/',$value)) throw new DomainException('Ungültige Prüfsumme.'); return $value; }
    private function json(array $value): string { return json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n"; }
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn($v)=>$this->canonical($v),$value);
        ksort($value); foreach ($value as $key=>$item) $value[$key]=$this->canonical($item); return $value;
    }
    private function hashValue(mixed $value): string { return hash('sha256',json_encode($this->canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
    private function readJson(string $file): array
    {
        if (!is_file($file)) throw new DomainException('Erforderliche Veröffentlichungsdatei fehlt: '.basename($file).'.');
        $value=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new DomainException('Ungültige Veröffentlichungsdatei.'); return $value;
    }
    private function safeManagedPath(string $path): string
    {
        if (!preg_match('#^media/cms/[a-zA-Z0-9][a-zA-Z0-9/_-]*\.(?:webp|jpg|jpeg|png|mp3|wav|ogg|mp4|webm)$#',$path) || str_contains($path,'..')) throw new DomainException('Nicht erlaubter verwalteter Medienpfad.');
        return $path;
    }
    private function candidate(string $id): array
    {
        $id=$this->hex($id); $dir=storage_path('app/private/'.config('cms.export_prefix').'/candidates/'.$id);
        if(\App\Models\Publication::where('hash',$id)->latest('id')->value('status')==='stale')throw new DomainException('Diese Vorschau ist veraltet. Bitte erstellen und prüfen Sie eine neue Vorschau.');
        $manifestFile=$dir.'/manifest.json'; $settingsFile=$dir.'/cms-settings.json';
        $manifest=$this->readJson($manifestFile); $receipt=$this->readJson($dir.'/receipt.json');
        if (($receipt['candidateHash']??null)!==$id || ($receipt['manifestSha256']??null)!==hash_file('sha256',$manifestFile) || ($receipt['settingsSha256']??null)!==hash_file('sha256',$settingsFile) || !is_int($receipt['adminUserId']??null) || $receipt['adminUserId']<1) throw new DomainException('Kandidaten-Beleg ist ungültig.');
        $settings=$this->readJson($settingsFile);
        if(isset($receipt['snapshotSha256'])&&(!is_file($dir.'/cms-draft-snapshot.json')||$receipt['snapshotSha256']!==hash_file('sha256',$dir.'/cms-draft-snapshot.json')))throw new DomainException('Der CMS-Stand des Kandidaten ist ungültig.');
        if (($settings['scope']??null)!=='next_draft' || ($settings['deploymentAuthorized']??null)!==false) throw new DomainException('Der Kandidat ist kein freigegebener nächster Entwurf.');
        app(ContentValidator::class)->validate($manifest,false,false);
        if (($manifest['sources']??[])!==[]) throw new DomainException('Private Quellen dürfen nicht in den Kandidaten gelangen.');
        $media=[];
        foreach (($receipt['media']??[]) as $entry) {
            $path=$this->safeManagedPath($entry['path']??''); $relative=substr($path,10); $file=$dir.'/media/'.$relative;
            if (!is_file($file) || ($entry['sha256']??null)!==hash_file('sha256',$file) || (int)($entry['size']??-1)!==filesize($file)) throw new DomainException('Medien-Payload des Kandidaten ist ungültig.');
            $media[$path]=['path'=>$path,'sha256'=>$entry['sha256'],'size'=>(int)$entry['size'],'source'=>$file,'assetId'=>$entry['assetId']??null];
        }
        $deletes=array_values(array_unique(array_map(fn($p)=>$this->safeManagedPath($p),$receipt['managedDeletes']??[])));
        return compact('manifest','receipt','media','deletes','dir');
    }
    private function active(): array
    {
        $file=config('cms.manifest'); if (!is_file($file)) throw new DomainException('Aktives Manifest fehlt.');
        $bytes=file_get_contents($file); $manifest=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        app(ContentValidator::class)->validate($manifest); return compact('manifest','bytes');
    }
    private function idMap(array $rows): array { $map=[]; foreach ($rows as $row) $map[$row['id']]=$row; ksort($map); return $map; }
    private function publicProjection(array $m): array
    {
        $m['projects']=array_values(array_filter($m['projects'],fn($p)=>$p['status']==='published'));
        $trackIds=[]; foreach ($m['projects'] as $p) foreach ($p['tracks'] as $t) $trackIds[$t['trackId']]=true;
        $m['tracks']=array_values(array_filter($m['tracks'],fn($t)=>$t['status']==='published' && (isset($trackIds[$t['id']]) || ($t['libraryVisible']??false))));
        $publishedTracks=array_fill_keys(array_column($m['tracks'],'id'),true);
        foreach ($m['projects'] as &$p) {
            $p['tracks']=array_values(array_filter($p['tracks'],fn($t)=>isset($publishedTracks[$t['trackId']])));
            $p['sourceIds']=[];
            // Older public manifests omit this optional map; an empty object has the
            // same public meaning and must not create a synthetic review change.
            if (empty((array)($p['externalLinks']??[]))) unset($p['externalLinks']);
        } unset($p);
        $m['site']['cv']=array_values(array_filter($m['site']['cv'],fn($r)=>in_array($r['status'],['published','needs-confirmation'],true)));
        $assetIds=[]; $add=function($id) use (&$assetIds){if($id)$assetIds[$id]=true;};
        foreach ($m['projects'] as $p) { foreach ((array)$p['memberships'] as $member) {$add($member['coverAssetId']);$add($member['hoverAssetId']??null);} foreach ($p['gallery'] as $id)$add($id);$add($p['localTrailerAssetId']??null);$add($p['localTrailerPosterId']??null); }
        foreach ($m['tracks'] as $t)$add($t['audioAssetId']); foreach ($m['site']['mediaSlots'] as $slot){$add($slot['image']);$add($slot['video']);$add($slot['poster']);}$add($m['site']['collective']['logoAssetId']??null);
        $m['assets']=array_values(array_filter($m['assets'],fn($a)=>isset($assetIds[$a['id']]) && $a['state']!=='test'));
        foreach ($m['assets'] as &$a) { unset($a['sourceSha256'],$a['origin'],$a['source']); $a['credit']=$a['credit']??null; if(!empty($a['duration']))$a['duration']=(float)sprintf('%.14g',$a['duration']); } unset($a);
        $m['sources']=[]; foreach (['projects','tracks','assets'] as $key)$m[$key]=$this->idMap($m[$key]); return $m;
    }
    private function equal(mixed $a,mixed $b): bool { return $this->canonical($a)===$this->canonical($b); }
    private function diff(mixed $a,mixed $b,string $path=''): array
    {
        if ($this->equal($a,$b)) return [];
        if (is_array($a) && is_array($b) && !array_is_list($a) && !array_is_list($b)) {
            $out=[]; $keys=array_unique([...array_keys($a),...array_keys($b)]); sort($keys);
            foreach ($keys as $key) $out=[...$out,...$this->diff($a[$key]??null,$b[$key]??null,$path.'/'.$key)]; return $out;
        }
        return [['path'=>$path,'before'=>$a,'after'=>$b]];
    }
    private function merge(array $old,array $next,array $before,array $after,array $managedDeletes): array
    {
        $merged=$old; $deleteMap=array_fill_keys($managedDeletes,true);
        foreach (['projects','tracks','assets'] as $key) {
            $incoming=$this->idMap($next[$key]); $rows=[];
            foreach ($old[$key] as $original) {
                $id=$original['id']; $target=$incoming[$id]??null; unset($incoming[$id]);
                if (!$target) {
                    if ($key==='assets' && !isset($deleteMap[$original['path']??''])) $rows[]=$original;
                    elseif (!isset($before[$key][$id])) $rows[]=$original;
                    continue;
                }
                if (!isset($before[$key][$id])) { if (!$this->equal($original,$target)) throw new DomainException('Kandidat kollidiert mit lokaler, nicht öffentlicher ID: '.$id); $rows[]=$original; continue; }
                $copy=$original; $fields=array_unique([...array_keys($before[$key][$id]),...array_keys($after[$key][$id])]);
                foreach ($fields as $field) if (!$this->equal($before[$key][$id][$field]??null,$after[$key][$id][$field]??null)) { if(array_key_exists($field,$target))$copy[$field]=$target[$field];else unset($copy[$field]); }
                $rows[]=$copy;
            }
            $merged[$key]=[...$rows,...array_values($incoming)];
        }
        foreach (array_unique([...array_keys($before['site']),...array_keys($after['site'])]) as $field) if (!$this->equal($before['site'][$field]??null,$after['site'][$field]??null)) { if(array_key_exists($field,$next['site']))$merged['site'][$field]=$next['site'][$field];else unset($merged['site'][$field]); }
        app(ContentValidator::class)->validate($merged,false,false);
        if (!$this->equal($this->publicProjection($merged),$after)) throw new DomainException('Zusammengeführter Stand weicht vom geprüften Kandidaten ab.');
        return $merged;
    }
    private function prepare(string $candidateHash): array
    {
        $candidate=$this->candidate($candidateHash); $active=$this->active();
        $before=$this->publicProjection($active['manifest']); $after=$this->publicProjection($candidate['manifest']); $changes=$this->diff($before,$after);
        if (count($changes)>100 || strlen($this->json($changes))>262144) throw new DomainException('Ungewöhnlich großes Änderungspaket ist blockiert.');
        $merged=$this->merge($active['manifest'],$candidate['manifest'],$before,$after,$candidate['deletes']);
        $bytes=$changes?$this->json($merged):$active['bytes'];
        $mediaChanges=[]; $oldAssets=$this->idMap($active['manifest']['assets']); $newAssets=$this->idMap($candidate['manifest']['assets']); $deletePaths=$candidate['deletes'];
        foreach ($newAssets as $id=>$asset) if (isset($oldAssets[$id]) && ($oldAssets[$id]['path']??null)!==($asset['path']??null) && str_starts_with($oldAssets[$id]['path']??'','media/cms/')) $deletePaths[]=$oldAssets[$id]['path'];
        foreach ($candidate['media'] as $path=>$entry) {
            $target=config('cms.managed_media_root').'/'.substr($path,10); $beforeSha=is_file($target)?hash_file('sha256',$target):null;
            if ($beforeSha!==$entry['sha256']) $mediaChanges[$path]=['path'=>$path,'beforeSha256'=>$beforeSha,'afterSha256'=>$entry['sha256']];
        }
        foreach (array_unique($deletePaths) as $path) { $target=config('cms.managed_media_root').'/'.substr($path,10); if(is_file($target))$mediaChanges[$path]=['path'=>$path,'beforeSha256'=>hash_file('sha256',$target),'afterSha256'=>null]; }
        ksort($mediaChanges);
        $plan=['version'=>2,'candidateHash'=>$candidateHash,'exportingAdminId'=>$candidate['receipt']['adminUserId'],'previousManifestHash'=>hash('sha256',$active['bytes']),'resultingManifestHash'=>hash('sha256',$bytes),'changes'=>$changes,'mediaChanges'=>array_values($mediaChanges),'productionReleased'=>false];
        return compact('plan','bytes','active','candidate');
    }
    private function describe(array $changes): array
    {
        $out=[];
        foreach ($changes as $change) {
            $path=$change['path'];
            if ($path==='/site/socialLinks') {
                $old=collect($change['before']??[])->keyBy('provider'); $new=collect($change['after']??[])->keyBy('provider');
                foreach ($new as $provider=>$link) if(!$old->has($provider))$out[]='+ Social Link: '.$link['label']; else {
                    if(($old[$provider]['url']??null)!==($link['url']??null))$out[]=$link['label'].' URL geändert';
                    if(($old[$provider]['visible']??true)!==($link['visible']??true))$out[]=$link['label'].(($link['visible']??true)?' eingeblendet':' ausgeblendet');
                    if(($old[$provider]['sort_order']??null)!==($link['sort_order']??null))$out[]=$link['label'].' Reihenfolge geändert';
                }
                foreach ($old as $provider=>$link) if(!$new->has($provider))$out[]='− Social Link: '.$link['label'];
            } elseif (preg_match('#^/assets/([^/]+)/(path|sha256)$#',$path,$m)) $out[]='Media „'.$m[1].'“: Datei ersetzt';
            elseif (preg_match('#^/assets/([^/]+)$#',$path,$m)) $out[]=($change['before']===null?'+ Neues Medium: ':'− Medium entfernt: ').$m[1];
            elseif (preg_match('#^/projects/([^/]+)(.*)$#',$path,$m)) {
                $part=trim($m[2],'/');
                $roles=['gallery'=>'Galerie/Reihenfolge','localTrailerAssetId'=>'Trailer-Video','localTrailerPosterId'=>'Trailer-Poster','memberships'=>'Richtungs-Cover/Hover','tracks'=>'Soundtrack/Reihenfolge'];
                $role=collect($roles)->first(fn($label,$prefix)=>str_starts_with($part,$prefix),$part);
                $out[]='Projekt „'.$m[1].'“: '.$role.' geändert';
            }
            elseif (preg_match('#^/site/mediaSlots/([^/]+)/([^/]+)$#',$path,$m)) {
                $locations=['hero'=>'Startseite','composerMenu'=>'Film Music','directingMenu'=>'Directing','about'=>'About'];
                $fields=['image'=>'Bild','video'=>'Video','poster'=>'Video-Poster'];
                $out[]=($locations[$m[1]]??$m[1]).': '.($fields[$m[2]]??$m[2]).' geändert';
            }
            else $out[]='Geändert: '.str_replace('/',' → ',trim($path,'/'));
        }
        return array_values(array_unique($out));
    }
    private function writeImmutable(string $file,string $bytes): void
    {
        if (!is_dir(dirname($file)) && !mkdir(dirname($file),0750,true) && !is_dir(dirname($file))) throw new DomainException('Veröffentlichungsverzeichnis konnte nicht erstellt werden.');
        if (is_file($file)) { if(file_get_contents($file)!==$bytes)throw new DomainException('Unveränderliche Prüfdatei wurde verändert.'); return; }
        if(file_put_contents($file.'.pending',$bytes,LOCK_EX)===false || !rename($file.'.pending',$file))throw new DomainException('Prüfdatei konnte nicht atomar gespeichert werden.');
    }
    public function review(string $candidateHash,int $userId): array
    {
        $prepared=$this->prepare($this->hex($candidateHash)); $plan=$prepared['plan']+['reviewedBy'=>$userId,'reviewedAt'=>now()->toIso8601String()]; $planHash=$this->hashValue($plan);
        $this->writeImmutable(config('cms.frontend_apply_state').'/plans/'.$planHash.'.json',$this->json($plan));
        app(AuditLogger::class)->record('publication',$candidateHash,'review',$planHash,['changes'=>count($plan['changes'])]);
        return ['kind'=>'review','candidateHash'=>$candidateHash,'planHash'=>$planHash,'timestamp'=>$plan['reviewedAt'],'exportingAdminId'=>$plan['exportingAdminId'],'validation'=>'gültig','changeCount'=>count($plan['changes']),'descriptions'=>$this->describe($plan['changes']),'affectedPaths'=>array_column($plan['mediaChanges'],'path')];
    }
    public function currentReview(): array
    {
        $event=\App\Models\AuditEvent::where('object_type','publication')->where('action','review')->latest('id')->first();
        if(!$event||!is_string($event->result_hash)||!preg_match('/^[a-f0-9]{64}$/',$event->result_hash))return ['kind'=>'empty'];
        $planHash=$event->result_hash;$file=config('cms.frontend_apply_state').'/plans/'.$planHash.'.json';
        if(is_file(config('cms.frontend_apply_state').'/plans/'.$planHash.'.stale')||!is_file($file))return ['kind'=>'stale'];
        try{$plan=$this->readJson($file);if($this->hashValue($plan)!==$planHash)return ['kind'=>'stale'];}
        catch(\Throwable){return ['kind'=>'stale'];}
        $candidateHash=$plan['candidateHash']??'';
        if(!preg_match('/^[a-f0-9]{64}$/',$candidateHash))return ['kind'=>'stale'];
        $candidate=\App\Models\Publication::where('hash',$candidateHash)->latest('id')->first();
        if(!$candidate||$candidate->status==='stale')return ['kind'=>'stale'];
        try{$draftHash=ManifestImporter::hash(app(ManifestExporter::class)->package());$activeHash=hash_file('sha256',config('cms.manifest'));}
        catch(\Throwable){return ['kind'=>'stale'];}
        if($activeHash===($plan['resultingManifestHash']??null))return ['kind'=>'applied'];
        if($draftHash!==$candidateHash||$activeHash!==($plan['previousManifestHash']??null)){
            app(DraftStateSnapshot::class)->markCandidatesStale([$candidateHash]);
            return ['kind'=>'stale'];
        }
        return ['kind'=>'current','candidateHash'=>$candidateHash,'planHash'=>$planHash,'timestamp'=>$plan['reviewedAt']??$event->created_at?->toIso8601String(),'validation'=>'gültig','changeCount'=>count($plan['changes']??[]),'descriptions'=>$this->describe($plan['changes']??[]),'affectedPaths'=>array_column($plan['mediaChanges']??[],'path')];
    }
    private function withLock(callable $callback): mixed
    {
        $state=config('cms.frontend_apply_state'); if(!is_dir($state) && !mkdir($state,0750,true) && !is_dir($state))throw new DomainException('Apply-Statusverzeichnis konnte nicht erstellt werden.');
        $lock=$state.'/operation.lock'; $handle=@fopen($lock,'x'); if(!$handle)throw new DomainException('Eine andere Veröffentlichung läuft bereits oder wurde unterbrochen.');
        fwrite($handle,(string)getmypid()); try{return $callback();}finally{fclose($handle);@unlink($lock);}
    }
    private function replaceFile(string $target,string $bytes): void
    {
        $temp=dirname($target).'/.cms-'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($temp,$bytes,LOCK_EX)===false)throw new DomainException('Temporäre Datei konnte nicht geschrieben werden.');
        if(!rename($temp,$target)){@unlink($temp);throw new DomainException('Datei konnte nicht atomar ersetzt werden.');}
    }
    public function apply(string $planHash,int $userId): array
    {
        $planHash=$this->hex($planHash);if(is_file(config('cms.frontend_apply_state').'/plans/'.$planHash.'.stale'))throw new DomainException('Diese geprüfte Vorschau ist veraltet. Bitte erstellen und prüfen Sie eine neue Vorschau.');$recorded=$this->readJson(config('cms.frontend_apply_state').'/plans/'.$planHash.'.json');
        if($this->hashValue($recorded)!==$planHash)throw new DomainException('Geprüfter Plan wurde verändert.');
        return $this->withLock(function() use($recorded,$planHash,$userId) {
            $candidate=$this->candidate($recorded['candidateHash']); $currentHash=hash_file('sha256',config('cms.manifest'));
            try{$draftHash=ManifestImporter::hash(app(ManifestExporter::class)->package());}catch(\Throwable){$draftHash=null;}
            if($draftHash!==$recorded['candidateHash']){app(DraftStateSnapshot::class)->markCandidatesStale([$recorded['candidateHash']]);throw new DomainException('Diese Vorschau ist nicht mehr aktuell. Bitte erstellen und prüfen Sie eine neue Vorschau.');}
            if($currentHash===$recorded['resultingManifestHash']){$this->promoteSnapshot($candidate,$recorded);return ['status'=>'already-current','planHash'=>$planHash];}
            $prepared=$this->prepare($recorded['candidateHash']);
            foreach(['candidateHash','previousManifestHash','resultingManifestHash','changes','mediaChanges'] as $key)if(!$this->equal($prepared['plan'][$key],$recorded[$key]))throw new DomainException('Kandidat oder aktiver Stand hat sich seit der Prüfung geändert. Bitte erneut prüfen.');
            $state=config('cms.frontend_apply_state'); $revisionId=now()->format('YmdHisv').'-'.$planHash; $revisionDir=$state.'/revisions/'.$revisionId;
            if(!mkdir($revisionDir.'/media',0750,true) && !is_dir($revisionDir.'/media'))throw new DomainException('Revision konnte nicht angelegt werden.');
            file_put_contents($revisionDir.'/manifest.json',$prepared['active']['bytes'],LOCK_EX);
            foreach($recorded['mediaChanges'] as $change)if($change['beforeSha256']){
                $relative=substr($this->safeManagedPath($change['path']),10);$source=config('cms.managed_media_root').'/'.$relative;$backup=$revisionDir.'/media/'.$relative;
                if(!is_dir(dirname($backup)))mkdir(dirname($backup),0750,true);if(!copy($source,$backup)||hash_file('sha256',$backup)!==$change['beforeSha256'])throw new DomainException('Medien-Backup konnte nicht verifiziert werden.');
            }
            $revision=['version'=>2,'planHash'=>$planHash,'candidateHash'=>$recorded['candidateHash'],'adminUserId'=>$userId,'previousManifestHash'=>$recorded['previousManifestHash'],'resultingManifestHash'=>$recorded['resultingManifestHash'],'mediaChanges'=>$recorded['mediaChanges'],'timestamp'=>now()->toIso8601String()];
            file_put_contents($revisionDir.'/revision.json',$this->json($revision),LOCK_EX);
            try {
                foreach($recorded['mediaChanges'] as $change)if($change['afterSha256']){
                    $path=$this->safeManagedPath($change['path']);$source=$prepared['candidate']['media'][$path]['source']??null;if(!$source)throw new DomainException('Geprüfte Mediendatei fehlt.');
                    $target=config('cms.managed_media_root').'/'.substr($path,10);if(!is_dir(dirname($target)))mkdir(dirname($target),0750,true);$this->replaceFile($target,file_get_contents($source));if(hash_file('sha256',$target)!==$change['afterSha256'])throw new DomainException('Angewendete Mediendatei konnte nicht verifiziert werden.');
                }
                if(hash_file('sha256',config('cms.manifest'))!==$recorded['previousManifestHash'])throw new DomainException('Aktives Manifest wurde seit der Prüfung verändert.');
                $this->replaceFile(config('cms.manifest'),$prepared['bytes']);
                foreach($recorded['mediaChanges'] as $change)if(!$change['afterSha256'])@unlink(config('cms.managed_media_root').'/'.substr($this->safeManagedPath($change['path']),10));
                $this->promoteSnapshot($prepared['candidate'],$recorded);
            } catch(\Throwable $e) {
                foreach($recorded['mediaChanges'] as $change){$target=config('cms.managed_media_root').'/'.substr($this->safeManagedPath($change['path']),10);if($change['beforeSha256']){$backup=$revisionDir.'/media/'.substr($change['path'],10);if(is_file($backup))$this->replaceFile($target,file_get_contents($backup));}else @unlink($target);}
                if(hash_file('sha256',config('cms.manifest'))!==$recorded['previousManifestHash'])$this->replaceFile(config('cms.manifest'),$prepared['active']['bytes']); throw $e;
            }
            app(AuditLogger::class)->record('publication',$recorded['candidateHash'],'apply',$planHash,['revision'=>$revisionId]);
            return ['status'=>'success','planHash'=>$planHash,'revision'=>$revisionId,'resultingManifestHash'=>$recorded['resultingManifestHash']];
        });
    }
    private function promoteSnapshot(array $candidate,array $plan): void
    {
        if(isset($candidate['receipt']['snapshotSha256']))app(DraftStateSnapshot::class)->promote($candidate['dir'].'/cms-draft-snapshot.json',$candidate['receipt']['snapshotSha256'],$plan['resultingManifestHash']);
        app(DraftPreviewService::class)->invalidateAll();
    }
    public function rollbackReview(int $userId): array
    {
        $folder=config('cms.frontend_apply_state').'/revisions'; $files=is_dir($folder)?glob($folder.'/*/revision.json'):[]; rsort($files); $current=hash_file('sha256',config('cms.manifest')); $revision=null;$revisionFile=null;
        foreach($files as $file){$value=$this->readJson($file);if(($value['resultingManifestHash']??null)===$current){$revision=$value;$revisionFile=$file;break;}}
        if(!$revision)throw new DomainException('Keine passende angewendete Revision für Rollback gefunden.');
        $backup=dirname($revisionFile).'/manifest.json';if(!is_file($backup)||hash_file('sha256',$backup)!==$revision['previousManifestHash'])throw new DomainException('Rollback-Backup ist ungültig.');
        $plan=['operation'=>'rollback','revision'=>basename(dirname($revisionFile)),'candidateHash'=>$revision['candidateHash'],'previousManifestHash'=>$current,'resultingManifestHash'=>$revision['previousManifestHash'],'mediaChanges'=>$revision['mediaChanges'],'reviewedBy'=>$userId];$planHash=$this->hashValue($plan);
        return ['kind'=>'rollback','planHash'=>$planHash,'timestamp'=>$revision['timestamp'],'candidateHash'=>$revision['candidateHash'],'affectedPaths'=>['content/manifest.json',...array_column($revision['mediaChanges'],'path')],'plan'=>$plan];
    }
    public function rollback(string $planHash,int $userId): array
    {
        return $this->withLock(function() use($planHash,$userId){$review=$this->rollbackReview($userId);if($review['planHash']!==$this->hex($planHash))throw new DomainException('Rollback-Bestätigung passt nicht zum aktuellen Stand.');$plan=$review['plan'];$dir=config('cms.frontend_apply_state').'/revisions/'.$plan['revision'];
            foreach($plan['mediaChanges'] as $change){$target=config('cms.managed_media_root').'/'.substr($this->safeManagedPath($change['path']),10);if($change['beforeSha256']){$backup=$dir.'/media/'.substr($change['path'],10);if(!is_file($backup)||hash_file('sha256',$backup)!==$change['beforeSha256'])throw new DomainException('Rollback-Medienbackup ist ungültig.');if(!is_dir(dirname($target)))mkdir(dirname($target),0750,true);$this->replaceFile($target,file_get_contents($backup));}else @unlink($target);}
            if(hash_file('sha256',config('cms.manifest'))!==$plan['previousManifestHash'])throw new DomainException('Aktiver Stand hat sich vor Rollback geändert.');$bytes=file_get_contents($dir.'/manifest.json');$this->replaceFile(config('cms.manifest'),$bytes);
            app(AuditLogger::class)->record('publication',$plan['candidateHash'],'rollback',$planHash,['revision'=>$plan['revision']]);return ['status'=>'success','resultingManifestHash'=>$plan['resultingManifestHash']];});
    }
}
