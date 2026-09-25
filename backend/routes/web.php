<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => redirect('/admin'));
Route::post('/admin-theme', function (\Illuminate\Http\Request $request) {
    $input=$request->validate(['theme'=>['required',\Illuminate\Validation\Rule::in(['dark','light','system'])]]);
    $request->user()->update(['admin_theme'=>$input['theme']]);
    return response()->json(['saved'=>true]);
})->middleware(['auth','throttle:60,1']);
Route::get('/admin-media/{asset}', function (\App\Models\MediaAsset $asset) {
    if ($asset->storage_path) {
        $root=realpath(config('cms.media_originals_root'));
        $file=realpath(storage_path('app/private/'.$asset->storage_path));
        if($root && $file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file)) return response()->file($file,['Cache-Control'=>'no-store'])->setPrivate();
    }
    foreach ([config('cms.media_root'), config('cms.private_media_root')] as $directory) {
        $root=realpath($directory);
        $file=realpath($directory.'/'.preg_replace('#^media/#','',$asset->path));
        if($root && $file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file)) return response()->file($file,['Cache-Control'=>'no-store'])->setPrivate();
    }
    abort(404);
})->middleware('auth')->name('admin.media');
Route::get('/admin-export/{publication}/{file}', function (\App\Models\Publication $publication,string $file) {
    abort_unless(in_array($file,['manifest.json','cms-settings.json'],true),404);
    return response()->download(storage_path('app/private/'.$publication->path.'/'.$file),$file,['Cache-Control'=>'private, no-store']);
})->middleware('auth')->name('admin.export');
