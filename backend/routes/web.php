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
Route::get('/admin/draft-preview/open', function () {
    try{$result=app(\App\Services\DraftPreviewService::class)->create((int)auth()->id());}
    catch(\Throwable $e){return response('<h1>Website-Vorschau nicht verfügbar</h1><p>'.e($e->getMessage()).'</p>',422,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow, noarchive']);}
    session(['draft_preview_token'=>$result['token']]);
    return redirect()->route('draft-preview.site',['route'=>'/']);
})->middleware(['auth','throttle:20,1'])->name('draft-preview.open');
Route::get('/draft-preview/site', function (\Illuminate\Http\Request $request) {
    $route=(string)$request->query('route','/');
    abort_unless(preg_match('#^(?:/|/(?:film-music|directing|legal|privacy)/|/projects/[a-z0-9]+(?:-[a-z0-9]+)*/)$#',$route),404);
    $token=(string)$request->session()->get('draft_preview_token','');
    try{$service=app(\App\Services\DraftPreviewService::class);$preview=$service->resolve($token);$url=rtrim(config('cms.preview_frontend_internal_url'),'\/').'/draft-preview/?route='.rawurlencode($route);$render=\Illuminate\Support\Facades\Http::timeout(120)->withToken($token)->accept('text/html')->post($url,['manifest'=>$service->manifest($preview),'expiresAt'=>$preview->expires_at->toIso8601String()]);}
    catch(\Throwable $e){return response('<h1>Website-Vorschau nicht verfügbar</h1><p>'.e($e->getMessage()).'</p>',403,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow, noarchive']);}
    if(!$render->successful())return response('<h1>Website-Vorschau nicht verfügbar</h1><p>Die Website-Darstellung konnte nicht geladen werden.</p>',502,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow, noarchive']);
    $html=$render->body();$frontend=rtrim(config('cms.preview_frontend_url'),'\/');
    foreach(['/@vite/','/@fs/','/src/','/node_modules/','/_astro/'] as $prefix){$html=str_replace('src="'.$prefix,'src="'.$frontend.$prefix,$html);$html=str_replace('href="'.$prefix,'href="'.$frontend.$prefix,$html);}
    $html=str_replace('href="/favicon.svg"','href="'.$frontend.'/favicon.svg"',$html);
    return response($html,200,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','X-Robots-Tag'=>'noindex, nofollow, noarchive']);
})->middleware(['auth','throttle:120,1'])->name('draft-preview.site');
Route::get('/draft-preview/session', function (\Illuminate\Http\Request $request) {
    try{$preview=app(\App\Services\DraftPreviewService::class)->resolve((string)($request->header('X-Draft-Preview-Token')?:$request->cookie('pianissimo_draft_preview')));$manifest=app(\App\Services\DraftPreviewService::class)->manifest($preview);}
    catch(\Throwable $e){return response()->json(['message'=>$e->getMessage()],403,['Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow, noarchive']);}
    return response()->json(['manifest'=>$manifest,'expiresAt'=>$preview->expires_at->toIso8601String()],200,['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','X-Robots-Tag'=>'noindex, nofollow, noarchive']);
})->middleware('throttle:120,1')->name('draft-preview.session');
Route::get('/media/preview/{file}', function (\Illuminate\Http\Request $request,string $file) {
    try{$preview=app(\App\Services\DraftPreviewService::class)->resolve((string)$request->session()->get('draft_preview_token',''));$media=app(\App\Services\DraftPreviewService::class)->media($preview,$file);}
    catch(\Throwable){abort(403);}
    return response()->file($media['path'],['Content-Type'=>$media['mime'],'Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff','X-Robots-Tag'=>'noindex, nofollow, noarchive']);
})->where('file','[a-z0-9-]+\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm)')->middleware(['auth','throttle:240,1'])->name('draft-preview.media');
