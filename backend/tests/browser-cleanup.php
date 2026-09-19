<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local')) throw new RuntimeException('Local only');
\App\Models\User::where('email','browser-qa@pianissimo.test')->delete();
\App\Models\Project::where('id','synthetic-browser-qa')->delete();
echo "Synthetic browser fixtures removed; local admin retained.\n";
