<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database')!=='pianissimo' || !app()->environment('local')) throw new RuntimeException('Local CMS only.');
$asset=\App\Models\MediaAsset::findOrFail('synthetic-self-service'); if($asset->type!=='image')throw new RuntimeException('Synthetic asset is not an image.');
$about=\App\Models\Setting::read('about'); data_set($about,'media.image',$asset->id); \App\Models\Setting::put('about',$about);
echo "Synthetic image assigned to the optional About draft slot; active frontend unchanged.\n";
