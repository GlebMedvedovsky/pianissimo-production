<?php
// Local browser QA only. Never creates/resets the real administrator's credentials.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local') || config('database.connections.mysql.database')!=='pianissimo') throw new RuntimeException('Local editorial QA only');
use App\Models\{User,Project,Setting};
use App\Services\ManifestImporter;
$path=storage_path('app/private/03b-test-user.json');
$baseline=storage_path('app/private/03b-baseline.json');
$action=$argv[1]??'';
if($action==='setup') {
    if(is_file($path) || User::where('email','synthetic-03b@example.test')->exists()) throw new RuntimeException('Fixture exists; inspect before retry');
    $admin=User::where('email','local-admin@pianissimo.test')->firstOrFail();
    $data=['adminId'=>$admin->id,'theme'=>$admin->admin_theme,'credentialsHash'=>hash('sha256',$admin->password),
        'appearance'=>Setting::read('appearance'),'projectHash'=>ManifestImporter::hash(ManifestImporter::snapshot(Project::findOrFail('abszission')))];
    file_put_contents($baseline,json_encode($data,JSON_PRETTY_PRINT));
    $credentials=['email'=>'synthetic-03b@example.test','password'=>bin2hex(random_bytes(24))];
    User::create([...$credentials,'name'=>'Synthetic 03B Theme Check']);
    file_put_contents($path,json_encode($credentials));chmod($path,0600);
    echo "Synthetic second user prepared privately; original credentials unchanged.\n";
} elseif($action==='restore-empty-fields') {
    // Repair ONLY the three optional values normalised by the earlier UI save.
    // Exact pre-test hash must prove this reconstructs the original full record.
    $data=json_decode(file_get_contents($baseline),true);$p=Project::findOrFail('abszission');
    $manifest=json_decode(file_get_contents(config('cms.manifest')),true);
    $source=collect($manifest['projects'])->firstWhere('id','abszission');
    $fields=['platform_links'=>$source['platformLinks'],'external_links'=>$source['externalLinks']??[],'seo'=>[]];
    $snapshot=ManifestImporter::snapshot($p);foreach($fields as $key=>$value)$snapshot[$key]=$value;
    if(ManifestImporter::hash($snapshot)!==$data['projectHash']) throw new RuntimeException('Restoration does not match saved baseline; refusing');
    $p->update($fields);echo "Exact original optional fields restored; no import performed.\n";
} elseif($action==='verify' || $action==='cleanup') {
    $data=json_decode(file_get_contents($baseline),true);$admin=User::findOrFail($data['adminId']);
    $result=['credentialsUnchanged'=>hash('sha256',$admin->password)===$data['credentialsHash'],
        'themeRestored'=>$admin->admin_theme===$data['theme'],
        'websiteColorsUnchanged'=>Setting::read('appearance')===$data['appearance'],
        'cmsProjectRestored'=>ManifestImporter::hash(ManifestImporter::snapshot(Project::findOrFail('abszission')))===$data['projectHash']];
    if(in_array(false,$result,true)) {echo json_encode($result);exit(1);}
    if($action==='cleanup') {
        User::where('email','synthetic-03b@example.test')->where('name','Synthetic 03B Theme Check')->delete();
        if(is_file($path))unlink($path);
    }
    echo json_encode($result,JSON_PRETTY_PRINT)."\n";
} else throw new RuntimeException('Use setup, verify or cleanup');
