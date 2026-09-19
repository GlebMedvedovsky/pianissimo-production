<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Track,Project};use Illuminate\Support\Facades\DB;
if(!app()->environment('local')||config('database.connections.mysql.database')!=='pianissimo')throw new RuntimeException('Local only');
$dir=storage_path('app/private/04a');$base=json_decode(file_get_contents($dir.'/database-before.json'),true);
$hash=hash_file('sha256',config('cms.manifest'));
DB::transaction(function()use($base){
 $row=collect($base['tracks'])->firstWhere('id','au-milieu-song');Track::findOrFail('au-milieu-song')->update(['emotions'=>json_decode($row['emotions'],true)]);
 foreach($base['memberships'] as $m)if($m['direction']==='music'&&!in_array($m['project_id'],['kinnhakenhank','silver-strings','rollen-am-klavier','dough','abszission','sternenmeer']))Project::findOrFail($m['project_id'])->memberships()->where('direction','music')->update(['sort_order'=>1000+$m['sort_order']]);
 Project::findOrFail('entering-wonderland')->memberships()->where('direction','music')->update(['sort_order'=>1100]);
});
if(hash_file('sha256',config('cms.manifest'))!==$hash)throw new RuntimeException('Frontend changed');
echo "Preserved Au milieu emotions and original relative order of remaining music cards; active unchanged.\n";
