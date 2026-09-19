<?php
// One-off, local editorial import of the reviewed 04A input. No frontend writes.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\{Project,Track,MediaAsset,CvEntry,Setting};
use App\Services\ManifestImporter;
if(!app()->environment('local')||config('database.connections.mysql.database')!=='pianissimo') throw new RuntimeException('Local editorial database only');
$dir=storage_path('app/private/04a');if(!is_dir($dir))mkdir($dir,0750,true);
$action=$argv[1]??'';
if($action==='backup'){
 $file=$dir.'/database-before.json';if(is_file($file))throw new RuntimeException('Baseline exists; never overwrite');
 $data=[];
 foreach(['users','media_assets','projects','memberships','project_media','tracks','project_tracks','cv_entries','settings','external_services','import_records','publications','migrations'] as $table)$data[$table]=DB::table($table)->get()->all();
 file_put_contents($file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));chmod($file,0600);
 echo 'Private CMS backup saved; SHA256 '.hash_file('sha256',$file)."\n";exit;
}
if($action!=='apply-reviewed-input')throw new RuntimeException('Expected backup or apply-reviewed-input');
if(!is_file($dir.'/database-before.json'))throw new RuntimeException('Backup missing');
if(is_file($dir.'/cms-applied.json'))throw new RuntimeException('Input already applied; inspect state, do not repeat');
$input=json_decode(file_get_contents($dir.'/content-input.json'),true,512,JSON_THROW_ON_ERROR);
$before=hash_file('sha256',config('cms.manifest'));
$summary=DB::transaction(function()use($input,$dir){
 foreach($input['assets'] as $a){if(MediaAsset::find($a['id']))throw new RuntimeException('Existing asset conflict '.$a['id']);MediaAsset::create($a);}
 foreach($input['tracks'] as $t){$r=Track::find($t['id']);if($r&&$t['id']!=='au-milieu-song')throw new RuntimeException('Existing track conflict');$r??=new Track;$r->fill($t);$r->saveQuietly();}
 foreach($input['projects'] as $p){
  $members=$p['memberships'];$gallery=$p['gallery'];$soundtrack=$p['soundtrack']??[];unset($p['memberships'],$p['gallery'],$p['soundtrack']);
  if(Project::find($p['id']))throw new RuntimeException('Existing project conflict');
  if($p['id']==='der-statist')$p['soundtrack_order_pending']=true;
  $r=Project::create($p);$r->memberships()->createMany($members);
  foreach($gallery as $i=>$id)$r->mediaAssignments()->create(['media_asset_id'=>$id,'slot'=>'gallery','sort_order'=>$i]);
  $r->soundtrack()->createMany($soundtrack);
 }
 foreach($input['years'] as $id=>$year)Project::findOrFail($id)->update(['year'=>$year,'year_status'=>null]);
 Project::findOrFail('au-milieu')->update(['genres'=>['Jazz','Songs']]);
 $groups=['kinnhakenhank'=>1,'silver-strings'=>1,'rollen-am-klavier'=>1,'dough'=>2,'abszission'=>2,'sternenmeer'=>3];
 $stable=1000;
 foreach(Project::orderBy('sort_order')->get() as $p){$m=$p->memberships()->where('direction','music')->first();if(!$m)continue;$m->update(['sort_order'=>isset($groups[$p->id])?$groups[$p->id]*100:$stable++]);if(isset($groups[$p->id]))$p->update(['editorial_notes'=>trim(($p->editorial_notes??'')."\n04A §6: Film Music Prio ".$groups[$p->id].'. Gleicher Rang innerhalb der Gruppe; vorhandene stabile Reihenfolge ist keine Einzelpriorität.')]);}
 foreach(['cello','kinnhakenhank','leaving-earth','karussell'] as $i=>$id)Project::findOrFail($id)->memberships()->where('direction','directing')->update(['sort_order'=>$i+1]);
 // The image formerly next to the Kinn story becomes a gallery item; do not lose any existing still.
 $k=Project::findOrFail('kinnhakenhank');$cover=$k->memberships()->where('direction','directing')->first()->cover_asset_id;
 $k->mediaAssignments()->firstOrCreate(['slot'=>'gallery','media_asset_id'=>$cover],['sort_order'=>-1]);
 $about=Setting::read('about')['media']['image'];MediaAsset::findOrFail($about)->update(['credit'=>'Samples unterm Sofa']);
 $cv=CvEntry::where('link','http://marieluisealcantara.de/')->firstOrFail();$cv->update(['link_text'=>'Marie-Luise Alcántara']);
 foreach(['impressum','privacy'] as $key)Setting::put($key,[...Setting::read($key),'reviewDraft'=>file_get_contents($dir.'/'.$key.'-draft.txt'),'status'=>'draft']);
 return ['projects'=>Project::count(),'tracks'=>Track::count(),'assets'=>MediaAsset::count(),'inputSha256'=>hash_file('sha256',$dir.'/content-input.json'),'productionReleased'=>false];
});
if(hash_file('sha256',config('cms.manifest'))!==$before)throw new RuntimeException('Active manifest unexpectedly changed');
file_put_contents($dir.'/cms-applied.json',json_encode([...$summary,'activeUnchanged'=>$before],JSON_PRETTY_PRINT));
echo json_encode($summary,JSON_PRETTY_PRINT)."\n";
