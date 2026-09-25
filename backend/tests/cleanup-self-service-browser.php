<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database')!=='pianissimo' || !app()->environment('local')) throw new RuntimeException('Local CMS only.');
$file=storage_path('app/private/browser-self-service-baseline.json');
$baseline=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):null;
if ($baseline) {
    \Illuminate\Support\Facades\DB::transaction(function() use($baseline) {
        \App\Models\Setting::put('about',$baseline['about']); \App\Models\Setting::put('social',$baseline['social']);
        $asset=\App\Models\MediaAsset::withTrashed()->find('synthetic-self-service'); if($asset)$asset->forceDelete();
        \App\Models\AuditEvent::where('id','>',$baseline['auditMax'])->delete();
    });
} else {
    $active=json_decode(file_get_contents(config('cms.manifest')),true,512,JSON_THROW_ON_ERROR);
    if (collect($active['assets']??[])->contains(fn($asset)=>($asset['id']??null)==='synthetic-self-service')) throw new RuntimeException('Cannot clean QA artifacts while the synthetic asset is active.');
}
\Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('media/originals/synthetic-self-service');
$candidateHashes=[];
$candidateRoot=storage_path('app/private/'.config('cms.export_prefix').'/candidates');
foreach (glob($candidateRoot.'/*',GLOB_ONLYDIR)?:[] as $directory) {
    $manifest=$directory.'/manifest.json'; if(!is_file($manifest))continue;
    $content=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
    if(!collect($content['assets']??[])->contains(fn($asset)=>($asset['id']??null)==='synthetic-self-service'))continue;
    if(dirname(realpath($directory))!==realpath($candidateRoot))throw new RuntimeException('Unexpected candidate cleanup path.');
    $candidateHashes[]=basename($directory); \Illuminate\Support\Facades\File::deleteDirectory($directory);
}
if($candidateHashes) {
    \App\Models\Publication::where('status','candidate')->whereIn('hash',$candidateHashes)->delete();
    foreach(glob(config('cms.frontend_apply_state').'/plans/*.json')?:[] as $plan){$value=json_decode(file_get_contents($plan),true);if(in_array($value['candidateHash']??null,$candidateHashes,true))@unlink($plan);}
    foreach(glob(config('cms.frontend_apply_state').'/revisions/*/revision.json')?:[] as $revision){$value=json_decode(file_get_contents($revision),true);if(in_array($value['candidateHash']??null,$candidateHashes,true))\Illuminate\Support\Facades\File::deleteDirectory(dirname($revision));}
}
if($baseline && hash_file('sha256',config('cms.manifest'))!==$baseline['manifestSha256']) throw new RuntimeException('Active manifest was not restored by browser rollback.');
@unlink($file);
echo "Synthetic self-service draft, files, candidate packages and review/apply artifacts removed; active manifest is clean.\n";
