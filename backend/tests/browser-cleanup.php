<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local')) throw new RuntimeException('Local only');
$previewService=app(\App\Services\DraftPreviewService::class);
\App\Models\DraftPreview::query()->pluck('user_id')->unique()->each(fn($userId)=>$previewService->invalidateForUser((int)$userId));
$previewService->purgeExpired();
\App\Models\User::where('email','browser-qa@pianissimo.test')->delete();
\App\Models\Project::where('id','synthetic-browser-qa')->delete();
\Illuminate\Support\Facades\Storage::disk('local')->delete('browser-qa.json');
echo "Synthetic browser fixtures removed; local admin retained.\n";
