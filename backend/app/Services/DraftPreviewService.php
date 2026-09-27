<?php
namespace App\Services;

use App\Models\{DraftPreview,MediaAsset};
use DomainException;
use Illuminate\Support\Facades\File;

class DraftPreviewService
{
    public function hasCurrentForUser(int $userId): bool
    {
        $preview=DraftPreview::where('user_id',$userId)->whereNull('invalidated_at')->where('expires_at','>',now())->latest('id')->first();
        if(!$preview)return false;
        try{$current=ManifestImporter::hash(app(ManifestExporter::class)->package(ContentValidator::POLICY_DRAFT_PREVIEW));}
        catch(\Throwable){return false;}
        return hash_equals($preview->draft_hash,$current);
    }

    public function create(int $userId): array
    {
        $this->purgeExpired();
        $package=app(ManifestExporter::class)->package(ContentValidator::POLICY_DRAFT_PREVIEW);
        $draftHash=ManifestImporter::hash($package);
        $manifest=$package['manifest'];
        $identifier=bin2hex(random_bytes(16));
        $relative='draft-previews/'.$identifier;
        $directory=rtrim(config('cms.preview_storage_root'),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$identifier;
        $pending=$directory.'.pending';
        if(!is_dir($pending)&&!mkdir($pending.'/media',0750,true)&&!is_dir($pending.'/media'))throw new DomainException('Die Website-Vorschau konnte nicht vorbereitet werden.');
        try {
            $media=[];
            foreach($manifest['assets'] as $asset)$media[$asset['id']]=$this->copyAsset($asset,$pending.'/media');
            $bytes=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            if(file_put_contents($pending.'/manifest.json',$bytes,LOCK_EX)===false)throw new DomainException('Die Vorschau-Daten konnten nicht gespeichert werden.');
            if(!rename($pending,$directory))throw new DomainException('Die Website-Vorschau konnte nicht atomar bereitgestellt werden.');
            $token=bin2hex(random_bytes(32));
            $this->invalidateForUser($userId);
            $preview=DraftPreview::create([
                'user_id'=>$userId,
                'token_hash'=>hash('sha256',$token),
                'draft_hash'=>$draftHash,
                'snapshot_path'=>$relative,
                'media'=>$media,
                'expires_at'=>now()->addMinutes((int)config('cms.preview_ttl_minutes',15)),
            ]);
            return ['preview'=>$preview,'token'=>$token,'draftHash'=>$draftHash];
        } catch (\Throwable $e) {
            File::deleteDirectory($pending);
            if(is_dir($directory)&&!DraftPreview::where('snapshot_path',$relative)->exists())File::deleteDirectory($directory);
            throw $e;
        }
    }

    public function resolve(string $token): DraftPreview
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new DomainException('Ungültige Vorschau-Berechtigung.');
        $preview=DraftPreview::where('token_hash',hash('sha256',$token))->first();
        if(!$preview||$preview->invalidated_at)throw new DomainException('Diese Website-Vorschau ist nicht mehr gültig.');
        if($preview->expires_at->isPast()){
            $this->invalidate($preview);
            throw new DomainException('Diese Website-Vorschau ist abgelaufen.');
        }
        try{$current=ManifestImporter::hash(app(ManifestExporter::class)->package(ContentValidator::POLICY_DRAFT_PREVIEW));}
        catch(\Throwable $e){$this->invalidate($preview);throw new DomainException('Der gespeicherte Entwurf kann nicht mehr sicher als Vorschau angezeigt werden.',0,$e);}
        if(!hash_equals($preview->draft_hash,$current)){
            $this->invalidate($preview);
            throw new DomainException('Der gespeicherte Entwurf wurde geändert. Bitte öffnen Sie eine neue Website-Vorschau.');
        }
        return $preview;
    }

    public function manifest(DraftPreview $preview): array
    {
        $directory=$this->directory($preview);
        $file=$directory.'/manifest.json';
        if(!is_file($file))throw new DomainException('Die Vorschau-Daten fehlen.');
        $manifest=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        app(ContentValidator::class)->validate($manifest,false,false,ContentValidator::POLICY_DRAFT_PREVIEW);
        foreach($manifest['assets'] as &$asset){
            $entry=$preview->media[$asset['id']]??null;
            $mediaFile=$entry?$directory.'/media/'.$entry['file']:null;
            if(!$entry||!is_file($mediaFile)||!hash_equals($entry['sha256'],hash_file('sha256',$mediaFile)))throw new DomainException('Ein Vorschau-Medium ist ungültig.');
            $asset['path']='media/preview/'.$entry['file'];
        }
        unset($asset);
        foreach($manifest['projects'] as &$project){
            $project['memberships']=(object)($project['memberships']??[]);
            $project['platformLinks']=(object)($project['platformLinks']??[]);
            if(array_key_exists('externalLinks',$project))$project['externalLinks']=(object)($project['externalLinks']??[]);
        }
        unset($project);
        foreach($manifest['tracks'] as &$track)$track['platformOverrides']=(object)($track['platformOverrides']??[]);
        unset($track);
        if(array_key_exists('artistLinks',$manifest['site']))$manifest['site']['artistLinks']=(object)($manifest['site']['artistLinks']??[]);
        if(array_key_exists('profileLinks',$manifest['site']))$manifest['site']['profileLinks']=(object)($manifest['site']['profileLinks']??[]);
        if(isset($manifest['site']['analytics']))$manifest['site']['analytics']['enabled']=false;
        return $manifest;
    }

    public function media(DraftPreview $preview,string $file): array
    {
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm)$/',$file))throw new DomainException('Ungültiges Vorschau-Medium.');
        $entry=collect($preview->media)->first(fn($item)=>($item['file']??null)===$file);
        if(!$entry&&str_contains($file,'-480.')){$original=str_replace('-480.','.',$file);$entry=collect($preview->media)->first(fn($item)=>($item['file']??null)===$original);}
        if(!$entry)throw new DomainException('Dieses Medium gehört nicht zur aktuellen Vorschau.');
        $path=$this->directory($preview).'/media/'.$entry['file'];
        if(!is_file($path)||!hash_equals($entry['sha256'],hash_file('sha256',$path))||(int)$entry['size']!==filesize($path))throw new DomainException('Das Vorschau-Medium ist ungültig.');
        return ['path'=>$path,'mime'=>$entry['mime'],'sha256'=>$entry['sha256'],'size'=>(int)$entry['size']];
    }

    public function invalidateForUser(int $userId): void
    {
        DraftPreview::where('user_id',$userId)->whereNull('invalidated_at')->get()->each(fn($preview)=>$this->invalidate($preview));
    }

    public function purgeExpired(): void
    {
        DraftPreview::where('expires_at','<=',now())->orWhereNotNull('invalidated_at')->get()->each(function($preview){
            $this->deleteFiles($preview);
            $preview->delete();
        });
    }

    private function invalidate(DraftPreview $preview): void
    {
        if(!$preview->invalidated_at)$preview->forceFill(['invalidated_at'=>now()])->save();
        $this->deleteFiles($preview);
    }

    private function copyAsset(array $asset,string $targetDirectory): array
    {
        $record=MediaAsset::find($asset['id']);
        if(!$record)throw new DomainException($asset['id'].': Medium fehlt im CMS.');
        $source=$this->source($record,$asset['path']);
        $size=filesize($source);
        if($size===false||$size>config('cms.media_max_kb')*1024)throw new DomainException($asset['id'].': Medium überschreitet das erlaubte Limit.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if(!isset(MediaStorage::MIME_EXTENSIONS[$mime])||MediaStorage::MIME_TYPES[$mime]!==$asset['type'])throw new DomainException($asset['id'].': Medientyp ist für die Vorschau nicht zulässig.');
        $sha=hash_file('sha256',$source);
        if($record->sha256&&!hash_equals($record->sha256,$sha))throw new DomainException($asset['id'].': Medien-Prüfsumme stimmt nicht überein.');
        $file=$asset['id'].'.'.MediaStorage::MIME_EXTENSIONS[$mime];
        if(!copy($source,$targetDirectory.'/'.$file))throw new DomainException($asset['id'].': Medium konnte nicht für die Vorschau kopiert werden.');
        return ['file'=>$file,'mime'=>$mime,'sha256'=>$sha,'size'=>$size];
    }

    private function source(MediaAsset $record,string $publicPath): string
    {
        if($record->storage_path){
            if(!preg_match('#^media/originals/[a-z0-9]+(?:-[a-z0-9]+)*/[A-Za-z0-9-]+\.[A-Za-z0-9]+$#',$record->storage_path))throw new DomainException($record->id.': Privater Medienpfad ist ungültig.');
            return $this->inside(config('cms.media_originals_root'),storage_path('app/private/'.$record->storage_path));
        }
        if(!preg_match('#^media/[a-zA-Z0-9/_-]+\.(?:webp|jpg|jpeg|png|mp3|wav|ogg|mp4|webm)$#',$publicPath)||str_contains($publicPath,'..'))throw new DomainException($record->id.': Öffentlicher Medienpfad ist ungültig.');
        $relative=preg_replace('#^media/#','',$publicPath);
        foreach([config('cms.media_root'),config('cms.private_media_root')] as $root){
            try{return $this->inside($root,$root.'/'.$relative);}catch(DomainException){}
        }
        throw new DomainException($record->id.': Mediendatei wurde nicht gefunden.');
    }

    private function inside(string $root,string $candidate): string
    {
        $root=realpath($root);$file=realpath($candidate);
        if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))throw new DomainException('Mediendatei liegt außerhalb des erlaubten Speichers.');
        return $file;
    }

    private function directory(DraftPreview $preview): string
    {
        if(!preg_match('#^draft-previews/[a-f0-9]{32}$#',$preview->snapshot_path))throw new DomainException('Ungültiger Vorschau-Speicherort.');
        return $this->insideDirectory(config('cms.preview_storage_root'),rtrim(config('cms.preview_storage_root'),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($preview->snapshot_path));
    }

    private function insideDirectory(string $root,string $candidate): string
    {
        $root=realpath($root);$directory=realpath($candidate);
        if(!$root||!$directory||!str_starts_with($directory,$root.DIRECTORY_SEPARATOR)||!is_dir($directory))throw new DomainException('Vorschau-Speicherort ist nicht verfügbar.');
        return $directory;
    }

    private function deleteFiles(DraftPreview $preview): void
    {
        if(preg_match('#^draft-previews/[a-f0-9]{32}$#',$preview->snapshot_path))File::deleteDirectory(rtrim(config('cms.preview_storage_root'),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($preview->snapshot_path));
    }
}
