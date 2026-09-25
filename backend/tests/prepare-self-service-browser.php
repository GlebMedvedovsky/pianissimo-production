<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database')!=='pianissimo' || !app()->environment('local')) throw new RuntimeException('Local CMS only.');
$file=storage_path('app/private/browser-self-service-baseline.json');
$asset=\App\Models\MediaAsset::withTrashed()->find('synthetic-self-service');
if ($asset) {
    if (is_file($file)) { $old=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR); \App\Models\Setting::put('about',$old['about']); \App\Models\Setting::put('social',$old['social']); }
    \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('media/originals/synthetic-self-service');
    $asset->forceDelete();
}
$baseline=['about'=>\App\Models\Setting::read('about'),'social'=>\App\Models\Setting::read('social'),'manifestSha256'=>hash_file('sha256',config('cms.manifest')),'auditMax'=>(int)(\App\Models\AuditEvent::max('id')??0)];
file_put_contents($file,json_encode($baseline,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX); chmod($file,0600);
echo "Synthetic self-service browser baseline prepared; no content values printed.\n";
