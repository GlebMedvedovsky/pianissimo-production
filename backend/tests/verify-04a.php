<?php
// Read-only checks of the current editorial state. Never prints credentials.
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Project,Track,MediaAsset,Setting,User};
if(!app()->environment('local')||config('database.connections.mysql.database')!=='pianissimo')throw new RuntimeException('Local only');
$assert=fn($ok,$label)=>$ok?:throw new RuntimeException($label);
$before=json_decode(file_get_contents(storage_path('app/private/04a/database-before.json')),true);
$users=User::all();$assert($users->count()===count($before['users']),'Admin count changed');
foreach($before['users'] as $u){$now=$users->firstWhere('id',$u['id']);$assert($now->password===$u['password']&&$now->admin_theme===$u['admin_theme'],'Admin password/theme changed');}
$letter=Project::findOrFail('letterboxd-everything-everywhere-all-at-once');$assert($letter->status==='draft'&&!$letter->external_film&&!$letter->local_trailer_asset_id&&$letter->memberships()->count()===0,'Letterboxd restriction');
$statist=Project::findOrFail('der-statist');$joins=$statist->soundtrack()->get();$assert($statist->status==='draft'&&$statist->soundtrack_order_pending&&$joins->count()===3,'Statist draft');$rows=[];
foreach($joins as $j){$t=Track::findOrFail($j->track_id);$assert($j->sort_order===null,'Guessed order');$assert($t->genres===['Acoustic']&&$t->status==='draft'&&!$t->library_visible,'Statist taxonomy/status');$a=MediaAsset::findOrFail($t->audio_asset_id);$suffix=preg_replace('#^media/#','',$a->path);$assert(!is_file(config('cms.media_root').'/'.$suffix),'Private audio in public');$assert(hash_file('sha256',config('cms.private_media_root').'/'.$suffix)===$a->sha256,'Private media hash');$rows[]=['id'=>$t->id,'genres'=>$t->genres,'emotions'=>$t->emotions,'order'=>$j->sort_order,'privatePlayback'=>true];}
$assert(Project::findOrFail('cello')->memberships()->where('direction','music')->count()===0,'Cello unexpected music membership');
foreach(['impressum','privacy'] as $key){$v=Setting::read($key);$assert($v['status']==='draft'&&strlen($v['reviewDraft']??'')>100,'Legal draft missing');}
echo json_encode(['cmsCounts'=>['projects'=>Project::count(),'tracks'=>Track::count(),'assets'=>MediaAsset::count()],'adminPasswordAndThemeUnchanged'=>true,'letterboxdDraftNoVideoNoCover'=>true,'statist'=>$rows,'celloDirectingOnly'=>true,'legalPrivateDrafts'=>true,'productionReleased'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
