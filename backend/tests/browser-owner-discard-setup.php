<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))throw new RuntimeException('Local only');
$stateFile=storage_path('app/private/browser-owner-discard-state.json');if(is_file($stateFile))throw new RuntimeException('Browser discard state already exists.');
$user=\App\Models\User::where('email','browser-qa@pianissimo.test')->firstOrFail();$aboutRow=(array)\Illuminate\Support\Facades\DB::table('settings')->where('id','about')->first();
$applyFile=config('cms.frontend_apply_state').'/applied-cms.json';$backup=$applyFile.'.browser-backup';if(is_file($backup))unlink($backup);$hadApply=is_file($applyFile);if($hadApply&&!copy($applyFile,$backup))throw new RuntimeException('Applied snapshot backup failed.');
$publicationRows=\App\Models\Publication::get(['id','status','updated_at'])->map(fn($row)=>$row->toArray())->all();$auditMax=(int)(\App\Models\AuditEvent::max('id')??0);
$about=\App\Models\Setting::read('about');$marker='Browser QA '.bin2hex(random_bytes(5));\App\Models\Setting::put('about',[...$about,'title'=>$marker]);
$snapshot=app(\App\Services\DraftStateSnapshot::class)->capture('browser-owner-baseline');$snapshot['manifestHash']=hash_file('sha256',config('cms.manifest'));if(!is_dir(dirname($applyFile)))mkdir(dirname($applyFile),0750,true);file_put_contents($applyFile,json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
$export=app(\App\Services\ManifestExporter::class)->export(false,false,$user->id);$review=app(\App\Services\FrontendPublication::class)->review($export['hash'],$user->id);
$state=['aboutRow'=>$aboutRow,'firstBiography'=>$about['biography'][0]??'','frontendSha256'=>hash_file('sha256',config('cms.manifest')),'candidateHash'=>$export['hash'],'candidateDirectory'=>storage_path('app/private/'.$export['path']),'planFile'=>config('cms.frontend_apply_state').'/plans/'.$review['planHash'].'.json','publicationRows'=>$publicationRows,'auditMax'=>$auditMax,'applyFile'=>$applyFile,'backup'=>$backup,'hadApply'=>$hadApply];
file_put_contents($stateFile,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);chmod($stateFile,0600);echo "Isolated browser discard baseline prepared.\n";
