<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database')!=='pianissimo' || !app()->environment('local')) throw new RuntimeException('Local CMS only.');
$adminPath=storage_path('app/private/local-admin.json');
if (!is_file($adminPath)) {
    if (\App\Models\User::where('email','local-admin@pianissimo.test')->exists()) throw new RuntimeException('Existing admin: use cms:admin --reset interactively.');
    $credentials=['email'=>'local-admin@pianissimo.test','password'=>bin2hex(random_bytes(24))];
    \App\Models\User::create([...$credentials,'name'=>'Pianissimo Local Admin']);
    file_put_contents($adminPath,json_encode($credentials,JSON_PRETTY_PRINT));
    chmod($adminPath,0600);
}
$secondary=['email'=>'browser-qa@pianissimo.test','password'=>bin2hex(random_bytes(24))];
\App\Models\User::updateOrCreate(['email'=>$secondary['email']],[...$secondary,'name'=>'Synthetic Browser QA','admin_theme'=>'dark']);
file_put_contents(storage_path('app/private/browser-qa.json'),json_encode($secondary));
chmod(storage_path('app/private/browser-qa.json'),0600);
echo "Local admin credentials retained privately; synthetic second user prepared. No passwords printed.\n";
