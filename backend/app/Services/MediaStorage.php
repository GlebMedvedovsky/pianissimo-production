<?php
namespace App\Services;

use App\Models\MediaAsset;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    public const MIME_EXTENSIONS=[
        'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
        'video/mp4'=>'mp4','video/webm'=>'webm',
        'audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg','application/ogg'=>'ogg',
    ];
    public const MIME_TYPES=[
        'image/jpeg'=>'image','image/png'=>'image','image/webp'=>'image',
        'video/mp4'=>'video','video/webm'=>'video',
        'audio/mpeg'=>'audio','audio/wav'=>'audio','audio/x-wav'=>'audio','audio/ogg'=>'audio','application/ogg'=>'audio',
    ];

    public static function acceptedMimeTypes(): array { return array_keys(self::MIME_EXTENSIONS); }

    public function inspectStored(string $relative, string $id, ?string $originalName=null): array
    {
        if (!preg_match('/^media\/originals\/(?:pending|[a-z0-9]+(?:-[a-z0-9]+)*)\/[A-Za-z0-9-]+\.[A-Za-z0-9]+$/',$relative)) throw new DomainException('Ungültiger privater Medienpfad.');
        $root=realpath(config('cms.media_originals_root'));
        $file=realpath(storage_path('app/private/'.$relative));
        if (!$root || !$file || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file)) throw new DomainException('Die hochgeladene Datei fehlt im privaten Speicher.');
        $size=filesize($file);
        if ($size===false || $size>config('cms.media_max_kb')*1024) throw new DomainException('Die Datei überschreitet das konfigurierte Upload-Limit.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!isset(self::MIME_EXTENSIONS[$mime])) throw new DomainException('Dieser Dateityp ist nicht als Web-Medium zugelassen.');
        $sha=hash_file('sha256',$file);
        $extension=self::MIME_EXTENSIONS[$mime];
        $canonical='media/originals/'.$id.'/'.$sha.'.'.$extension;
        if ($relative!==$canonical) {
            Storage::disk('local')->makeDirectory('media/originals/'.$id);
            if (!Storage::disk('local')->exists($canonical)) Storage::disk('local')->move($relative,$canonical); else Storage::disk('local')->delete($relative);
            $relative=$canonical; $file=storage_path('app/private/'.$relative);
        }
        $metadata=['storage_path'=>$relative,'original_name'=>Str::limit($originalName ?: basename($file),255,''),'mime_type'=>$mime,'file_size'=>$size,
            'type'=>self::MIME_TYPES[$mime],'sha256'=>$sha,'path'=>'media/cms/'.$id.'-'.substr($sha,0,16).'.'.$extension];
        if ($metadata['type']==='image') {
            $dimensions=@getimagesize($file);
            if (!$dimensions) throw new DomainException('Die Bilddatei konnte nicht sicher gelesen werden.');
            [$metadata['width'],$metadata['height']]=$dimensions;
            $metadata['duration']=null;
        } else {
            $metadata['width']=$metadata['height']=null;
            $metadata['duration']=null;
        }
        return $metadata;
    }

    public function registerExisting(array $data): array
    {
        $root=realpath(config('cms.media_root'));
        $path=(string)($data['path']??'');
        $extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        $extensionType=in_array($extension,['jpg','jpeg','png','webp'],true)?'image':(in_array($extension,['mp4','webm'],true)?'video':(in_array($extension,['mp3','wav','ogg'],true)?'audio':null));
        if (!$extensionType || $extensionType!==($data['type']??null)) throw new DomainException('Dateiendung und gewählter Medientyp stimmen nicht überein.');
        $file=realpath(config('cms.media_root').'/'.preg_replace('#^media/#','',$path));
        // Legacy/advanced registration may describe a web file staged outside this
        // local checkout. Uploaded files always take the stricter private-storage path.
        if (!$root || !$file || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file)) return [...$data,'storage_path'=>null];
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!isset(self::MIME_EXTENSIONS[$mime]) || self::MIME_TYPES[$mime]!==($data['type']??null)) throw new DomainException('Dateiinhalt und gewählter Medientyp stimmen nicht überein.');
        $data['mime_type']=$mime; $data['file_size']=filesize($file); $data['sha256']=hash_file('sha256',$file); $data['original_name']=basename($file); $data['storage_path']=null;
        if ($data['type']==='image' && ($dimensions=@getimagesize($file))) [$data['width'],$data['height']]=$dimensions;
        return $data;
    }

    public function replace(MediaAsset $asset, string $relative, ?string $originalName=null): void
    {
        $metadata=$this->inspectStored($relative,$asset->id,$originalName);
        if ($metadata['type']!==$asset->type) throw new DomainException('Die Ersatzdatei muss denselben Medientyp haben.');
        $asset->update($metadata);
        app(AuditLogger::class)->record('media',$asset->id,'replace',$metadata['sha256'],['mime'=>$metadata['mime_type'],'size'=>$metadata['file_size']]);
    }
}
