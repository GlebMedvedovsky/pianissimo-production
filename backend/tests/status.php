<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode([
    'php'=>PHP_VERSION,'laravel'=>app()->version(),
    'filament'=>\Composer\InstalledVersions::getPrettyVersion('filament/filament'),
    'database'=>\Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version,
    'projects'=>\App\Models\Project::count(),'tracks'=>\App\Models\Track::count(),'assets'=>\App\Models\MediaAsset::count(),
    'cv'=>\App\Models\CvEntry::count(),'users'=>\App\Models\User::count(),'publications'=>\App\Models\Publication::count(),
    'manifestSha256'=>hash_file('sha256',config('cms.manifest')),
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
