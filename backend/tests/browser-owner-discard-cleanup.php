<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))throw new RuntimeException('Local only');
$stateFile=storage_path('app/private/browser-owner-discard-state.json');if(!is_file($stateFile))throw new RuntimeException('Browser discard state missing.');$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
\Illuminate\Support\Facades\DB::table('settings')->where('id','about')->update(['data'=>$state['aboutRow']['data'],'created_at'=>$state['aboutRow']['created_at'],'updated_at'=>$state['aboutRow']['updated_at']]);
$before=collect($state['publicationRows'])->keyBy('id');\App\Models\Publication::whereNotIn('id',$before->keys())->delete();foreach($before as $id=>$row)\Illuminate\Support\Facades\DB::table('publications')->where('id',$id)->update(['status'=>$row['status'],'updated_at'=>\Illuminate\Support\Carbon::parse($row['updated_at'])->format('Y-m-d H:i:s')]);
\App\Models\AuditEvent::where('id','>',$state['auditMax'])->delete();
$private=realpath(storage_path('app/private'));$candidate=realpath($state['candidateDirectory']);if($candidate&&str_starts_with($candidate,$private.DIRECTORY_SEPARATOR))\Illuminate\Support\Facades\File::deleteDirectory($candidate);
foreach([$state['planFile'],substr($state['planFile'],0,-5).'.stale'] as $file)if(is_file($file))unlink($file);
if($state['hadApply']){if(!is_file($state['backup'])||!rename($state['backup'],$state['applyFile']))throw new RuntimeException('Applied snapshot restore failed.');}else{if(is_file($state['applyFile']))unlink($state['applyFile']);if(is_file($state['backup']))unlink($state['backup']);}
unlink($stateFile);echo "Browser discard baseline restored.\n";
