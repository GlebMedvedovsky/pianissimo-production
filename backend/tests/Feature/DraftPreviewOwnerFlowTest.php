<?php
namespace Tests\Feature;

use App\Filament\Pages\Publication as PublicationPage;
use App\Models\{AuditEvent,DraftPreview,MediaAsset,Publication,Setting,User};
use App\Services\{ContentValidator,DraftPreviewService,DraftStateSnapshot,FrontendPublication,ManifestExporter,ManifestImporter,MediaStorage};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File,Http,Storage};
use Livewire\Livewire;
use Tests\TestCase;

class DraftPreviewOwnerFlowTest extends TestCase
{
    use RefreshDatabase;
    private string $fixtureRoot;
    private User $admin;
    private array $privateMediaIds=[];

    protected function setUp(): void
    {
        parent::setUp();
        app(ManifestImporter::class)->import(json_decode(file_get_contents(config('cms.manifest')),true));
        $this->fixtureRoot=storage_path('app/private/draft-preview-tests/'.getmypid().'-'.bin2hex(random_bytes(4)));
        mkdir($this->fixtureRoot.'/managed',0750,true);copy(config('cms.manifest'),$this->fixtureRoot.'/manifest.json');
        config([
            'cms.manifest'=>$this->fixtureRoot.'/manifest.json',
            'cms.managed_media_root'=>$this->fixtureRoot.'/managed',
            'cms.frontend_apply_state'=>$this->fixtureRoot.'/apply',
            'cms.export_prefix'=>'draft-preview-tests/'.basename($this->fixtureRoot).'/exports',
            'cms.preview_storage_root'=>$this->fixtureRoot.'/previews',
            'cms.preview_package_root'=>$this->fixtureRoot.'/preview-packages',
            'cms.preview_incoming_root'=>$this->fixtureRoot.'/preview-incoming',
            'cms.preview_result_root'=>$this->fixtureRoot.'/preview-results',
            'cms.preview_ttl_minutes'=>15,
            'cms.preview_driver'=>'local-http',
        ]);
        $this->admin=User::create(['name'=>'Preview QA','email'=>bin2hex(random_bytes(4)).'@example.test','password'=>bin2hex(random_bytes(20))]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        foreach($this->privateMediaIds as $id)Storage::disk('local')->deleteDirectory('media/originals/'.$id);
        if(isset($this->fixtureRoot))File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_preview_uses_saved_draft_and_does_not_include_unsaved_values(): void
    {
        $about=Setting::read('about');$saved='Gespeicherter Vorschau-Text '.bin2hex(random_bytes(3));
        Setting::put('about',[...$about,'biography'=>[$saved]]);
        $unsaved=[...Setting::read('about'),'biography'=>['Nicht gespeicherter Browserwert']];
        $created=app(DraftPreviewService::class)->create($this->admin->id);
        $manifest=app(DraftPreviewService::class)->manifest($created['preview']);
        $this->assertSame([$saved],$manifest['site']['biography']);
        $this->assertNotSame($unsaved['biography'],$manifest['site']['biography']);
    }

    public function test_preview_is_strictly_read_only_for_active_manifest_media_and_publication_history(): void
    {
        $manifestBefore=file_get_contents(config('cms.manifest'));$mediaBefore=$this->tree(config('cms.managed_media_root'));
        $applyBefore=AuditEvent::where('object_type','publication')->where('action','apply')->count();
        $publishedBefore=Publication::where('status','published')->count();
        $created=app(DraftPreviewService::class)->create($this->admin->id);
        app(DraftPreviewService::class)->manifest($created['preview']);
        $this->assertSame($manifestBefore,file_get_contents(config('cms.manifest')));
        $this->assertSame($mediaBefore,$this->tree(config('cms.managed_media_root')));
        $this->assertSame($applyBefore,AuditEvent::where('object_type','publication')->where('action','apply')->count());
        $this->assertSame($publishedBefore,Publication::where('status','published')->count());
    }

    public function test_unconfirmed_private_draft_image_is_copied_only_into_authorized_snapshot(): void
    {
        $asset=$this->jpg('qa-preview-image','temporary','unconfirmed');$about=Setting::read('about');Setting::put('about',[...$about,'media'=>[...$about['media'],'image'=>$asset->id]]);
        $created=app(DraftPreviewService::class)->create($this->admin->id);$manifest=app(DraftPreviewService::class)->manifest($created['preview']);
        $projected=collect($manifest['assets'])->firstWhere('id',$asset->id);
        $this->assertSame('media/preview/'.$asset->id.'.jpg',$projected['path']);
        $this->assertSame('unconfirmed',$asset->rights_scope);
        $media=app(DraftPreviewService::class)->media($created['preview'],$asset->id.'.jpg');
        $this->assertSame($asset->sha256,hash_file('sha256',$media['path']));
        $this->assertFileDoesNotExist(config('cms.managed_media_root').'/'.basename($asset->path));

        $release=$manifest;$release['mode']='release';
        try{app(ContentValidator::class)->validate($release,true,false);$this->fail('Temporary media must remain blocked for release.');}
        catch(\DomainException $e){$this->assertStringContainsString('temporäre Medien',$e->getMessage());}
    }

    public function test_test_media_renders_in_private_preview_but_regular_package_rejects_it_and_unrelated_media_is_not_exposed(): void
    {
        $asset=$this->jpg('qa-preview-test','test','unconfirmed');$unrelated=$this->jpg('qa-preview-unrelated','test','unconfirmed');
        $about=Setting::read('about');Setting::put('about',[...$about,'media'=>[...$about['media'],'image'=>$asset->id]]);
        $manifestBefore=file_get_contents(config('cms.manifest'));$managedBefore=$this->tree(config('cms.managed_media_root'));

        $created=app(DraftPreviewService::class)->create($this->admin->id);$manifest=app(DraftPreviewService::class)->manifest($created['preview']);
        $projected=collect($manifest['assets'])->firstWhere('id',$asset->id);
        $this->assertSame('test',$projected['state']);
        $this->assertSame('media/preview/'.$asset->id.'.jpg',$projected['path']);
        $this->assertArrayHasKey($asset->id,$created['preview']->media);
        $this->assertArrayNotHasKey($unrelated->id,$created['preview']->media);
        $this->withSession(['draft_preview_token'=>$created['token']])->get(route('draft-preview.media',$asset->id.'.jpg'))->assertOk()->assertHeader('Content-Type','image/jpeg');
        try{app(DraftPreviewService::class)->media($created['preview'],$unrelated->id.'.jpg');$this->fail('Unreferenced private media must not be exposed.');}
        catch(\DomainException $e){$this->assertStringContainsString('gehört nicht',$e->getMessage());}
        $this->assertSame($manifestBefore,file_get_contents(config('cms.manifest')));
        $this->assertSame($managedBefore,$this->tree(config('cms.managed_media_root')));

        try{app(ManifestExporter::class)->package();$this->fail('A regular publication package must still reject test media.');}
        catch(\DomainException $e){$this->assertSame('Test-Medium im regulären Paket.',$e->getMessage());}
    }

    public function test_discard_restores_last_applied_about_image_for_the_next_preview(): void
    {
        $appliedImage=Setting::read('about')['media']['image'];
        app(DraftStateSnapshot::class)->preview();
        $asset=$this->jpg('qa-preview-discard','test','unconfirmed');$about=Setting::read('about');
        Setting::put('about',[...$about,'media'=>[...$about['media'],'image'=>$asset->id]]);
        $draft=app(DraftPreviewService::class)->create($this->admin->id);
        $this->assertSame($asset->id,app(DraftPreviewService::class)->manifest($draft['preview'])['site']['mediaSlots']['about']['image']);

        app(DraftStateSnapshot::class)->discard($this->admin->id);
        try{app(DraftPreviewService::class)->resolve($draft['token']);$this->fail('Discard must invalidate the old preview.');}catch(\DomainException){}
        $restored=app(DraftPreviewService::class)->create($this->admin->id);
        $this->assertSame($appliedImage,app(DraftPreviewService::class)->manifest($restored['preview'])['site']['mediaSlots']['about']['image']);
    }

    public function test_preview_routes_require_valid_unexpired_token_and_send_noindex_no_store(): void
    {
        $this->get(route('draft-preview.session'))->assertForbidden()->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        $this->withHeader('X-Draft-Preview-Token','invalid')->get(route('draft-preview.session'))->assertForbidden();
        $created=app(DraftPreviewService::class)->create($this->admin->id);
        $response=$this->withHeader('X-Draft-Preview-Token',$created['token'])->get(route('draft-preview.session'));
        $response->assertOk()->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        $this->assertStringContainsString('no-store',(string)$response->headers->get('Cache-Control'));
        $response->assertJsonPath('manifest.site.analytics.enabled',false);
        $this->assertMatchesRegularExpression('/"platformLinks"\s*:\s*\{/', $response->getContent());
        $this->assertSame(Setting::read('consent')['externalMedia'],$response->json('manifest.site.consent.externalMedia'));
        $created['preview']->update(['expires_at'=>now()->subSecond()]);
        $this->withHeader('X-Draft-Preview-Token',$created['token'])->get(route('draft-preview.session'))->assertForbidden();
    }

    public function test_open_action_requires_admin_and_keeps_token_out_of_url(): void
    {
        auth()->logout();$this->get(route('draft-preview.open'))->assertRedirect();
        $this->actingAs($this->admin);$response=$this->get(route('draft-preview.open'));
        $response->assertRedirect();
        $location=(string)$response->headers->get('Location');$this->assertStringNotContainsString('token=',$location);
        $this->assertStringContainsString('/draft-preview/site',$location);parse_str((string)parse_url($location,PHP_URL_QUERY),$query);
        $this->assertMatchesRegularExpression('/^\d+$/',(string)($query['preview']??''));
    }

    public function test_package_preview_dispatches_private_build_and_never_calls_local_frontend_renderer(): void
    {
        config([
            'cms.preview_driver'=>'package',
            'cms.source_revision'=>str_repeat('a',40),
            'publisher.github.repository'=>'pianissimo/production',
            'publisher.github.ref'=>'main',
            'publisher.github.preview_workflow'=>'pianissimo-preview-builder.yml',
            'publisher.github.token'=>str_repeat('g',40),
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/pianissimo/production/actions/workflows/pianissimo-preview-builder.yml/dispatches'=>Http::response('',204)]);

        $open=$this->get(route('draft-preview.open'))->assertRedirect();
        $location=(string)$open->headers->get('Location');
        $this->get($location)->assertStatus(202)->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');

        Http::assertSentCount(1);
        Http::assertSent(fn($request)=>$request->url()==='https://api.github.com/repos/pianissimo/production/actions/workflows/pianissimo-preview-builder.yml/dispatches'
            && $request['inputs']['source_revision']===str_repeat('a',40)
            && preg_match('/^[a-f0-9]{32}$/D',$request['inputs']['preview_identifier'])===1);
        $this->assertStringNotContainsString('host.docker.internal',Http::recorded()[0][0]->url());
    }

    public function test_local_http_preview_still_posts_saved_manifest_to_development_renderer(): void
    {
        config(['cms.preview_driver'=>'local-http','cms.preview_frontend_internal_url'=>'http://host.docker.internal:4321','cms.preview_frontend_url'=>'http://127.0.0.1:4321']);
        $created=app(DraftPreviewService::class)->create($this->admin->id);
        Http::fake(['http://host.docker.internal:4321/draft-preview/*'=>Http::response('<!doctype html><html><head><script src="/@vite/client"></script></head><body>LOCAL PREVIEW</body></html>',200,['Content-Type'=>'text/html'])]);
        $response=$this->withSession(['draft_preview_token'=>$created['token']])->get(route('draft-preview.site',['preview'=>$created['preview']->id,'route'=>'/']));
        $response->assertOk()->assertSee('LOCAL PREVIEW',false)->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        Http::assertSent(fn($request)=>$request->method()==='POST'&&str_starts_with($request->url(),'http://host.docker.internal:4321/draft-preview/'));
    }

    public function test_package_transport_is_private_authenticated_and_bound_to_unexpired_preview(): void
    {
        $publisherToken=str_repeat('p',48);
        config(['cms.preview_driver'=>'package','cms.source_revision'=>str_repeat('a',40),'publisher.api_token'=>$publisherToken]);
        $created=app(DraftPreviewService::class)->create($this->admin->id);
        $identifier=basename($created['preview']->snapshot_path);
        $url='/api/publisher/v1/previews/'.$identifier.'/package';

        $unauthorized=$this->get($url)->assertUnauthorized();$this->assertStringContainsString('no-store',(string)$unauthorized->headers->get('Cache-Control'));
        $response=$this->withHeader('Authorization','Bearer '.$publisherToken)->get($url)->assertOk()->assertHeader('Content-Type','application/x-tar');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',(string)$response->headers->get('X-Pianissimo-Transport-Sha256'));
        $this->assertSame($created['package']['packageSha256'],$response->headers->get('X-Pianissimo-Package-Sha256'));

        $created['preview']->update(['expires_at'=>now()->subSecond()]);
        $this->withHeader('Authorization','Bearer '.$publisherToken)->get($url)->assertServerError();
    }

    public function test_preview_media_checksum_tampering_remains_blocked(): void
    {
        $asset=$this->jpg('qa-preview-tamper','test','unconfirmed');$about=Setting::read('about');Setting::put('about',[...$about,'media'=>[...$about['media'],'image'=>$asset->id]]);
        $created=app(DraftPreviewService::class)->create($this->admin->id);
        $media=$created['preview']->media[$asset->id];
        file_put_contents($this->fixtureRoot.'/previews/'.basename($created['preview']->snapshot_path).'/media/'.$media['file'],'tampered');
        $this->withSession(['draft_preview_token'=>$created['token']])->get(route('draft-preview.media',$media['file']))->assertForbidden();
    }

    public function test_new_saved_draft_invalidates_old_snapshot_and_new_preview_contains_new_value(): void
    {
        $about=Setting::read('about');Setting::put('about',[...$about,'biography'=>['Draft A']]);$a=app(DraftPreviewService::class)->create($this->admin->id);
        Setting::put('about',[...$about,'biography'=>['Draft B']]);
        $this->assertNotNull($a['preview']->fresh()->invalidated_at,'Saving Draft B must invalidate Preview A immediately.');
        try{app(DraftPreviewService::class)->resolve($a['token']);$this->fail('Draft A token must become stale.');}catch(\DomainException $e){$this->assertStringContainsString('geändert',$e->getMessage());}
        $b=app(DraftPreviewService::class)->create($this->admin->id);$this->assertSame(['Draft B'],app(DraftPreviewService::class)->manifest($b['preview'])['site']['biography']);
    }

    public function test_preview_status_binds_each_tab_to_its_snapshot_and_revalidates_saved_draft(): void
    {
        $about=Setting::read('about');Setting::put('about',[...$about,'biography'=>['Draft A']]);$a=app(DraftPreviewService::class)->create($this->admin->id);
        $status=$this->withSession(['draft_preview_token'=>$a['token']])->get(route('draft-preview.status',['preview'=>$a['preview']->id]))->assertNoContent();
        $this->assertStringContainsString('no-store',(string)$status->headers->get('Cache-Control'));

        $b=app(DraftPreviewService::class)->create($this->admin->id);
        $this->withSession(['draft_preview_token'=>$b['token']])->get(route('draft-preview.status',['preview'=>$a['preview']->id]))->assertForbidden();
        $this->withSession(['draft_preview_token'=>$b['token']])->get(route('draft-preview.status',['preview'=>$b['preview']->id]))->assertNoContent();

        Setting::put('about',[...$about,'biography'=>['Draft B']]);
        $this->assertNotNull($b['preview']->fresh()->invalidated_at);
        $this->withSession(['draft_preview_token'=>$b['token']])->get(route('draft-preview.status',['preview'=>$b['preview']->id]))->assertForbidden();
    }

    public function test_preview_font_proxy_is_authenticated_and_strictly_allowlisted(): void
    {
        auth()->logout();
        $fontPath='C:/workspace/node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2';
        $this->get('/@fs/'.$fontPath)->assertRedirect();

        $this->actingAs($this->admin);
        Http::fake([
            rtrim(config('cms.preview_frontend_internal_url'),'/').'/@fs/'.$fontPath=>Http::response('font-bytes',200,['Content-Type'=>'font/woff2']),
        ]);
        $response=$this->get('/@fs/'.$fontPath)->assertOk()->assertHeader('Content-Type','font/woff2');
        $this->assertStringContainsString('no-store',(string)$response->headers->get('Cache-Control'));
        $this->get('/@fs/C:/workspace/private/secret.woff2')->assertNotFound();
        $this->get('/@fs/C:/workspace/node_modules/@fontsource/inter/files/../../package.json')->assertNotFound();
    }

    public function test_one_owner_review_action_prepares_candidate_review_and_blocks_stale_apply(): void
    {
        $social=Setting::read('social');Setting::put('social',[...$social,'links'=>[['provider'=>'vimeo','label'=>'Vimeo','url'=>'https://vimeo.com/example','visible'=>true,'sort_order'=>0]]]);
        Livewire::test(PublicationPage::class)->call('review')->assertSet('result.kind','review')->assertSee('Änderungen geprüft');
        $this->assertSame('current',app(FrontendPublication::class)->currentReview()['kind']);
        Setting::put('social',[...$social,'links'=>[]]);
        $this->assertSame('stale',app(FrontendPublication::class)->currentReview()['kind']);
        Livewire::test(PublicationPage::class)->assertSee('Die geprüften Änderungen sind nicht mehr aktuell')->assertActionHidden('apply');
        Livewire::test(PublicationPage::class)->call('review')->assertSet('result.kind','review');
        $this->assertSame('current',app(FrontendPublication::class)->currentReview()['kind']);
    }

    public function test_validation_failure_stops_combined_review_and_apply(): void
    {
        Setting::put('analytics',['enabled'=>true,'provider'=>'none','measurementId'=>null,'siteDomain'=>null]);
        Livewire::test(PublicationPage::class)->call('review')->assertSet('result.error',fn($value)=>is_string($value)&&$value!=='')->assertActionHidden('apply');
        $this->assertNotSame('current',app(FrontendPublication::class)->currentReview()['kind']);
    }

    private function jpg(string $id='qa-preview-image',string $state='temporary',string $rightsScope='next_draft'): MediaAsset
    {
        $bytes=base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==');
        $this->privateMediaIds[]=$id;$relative='media/originals/'.$id.'/upload.jpg';Storage::disk('local')->put($relative,$bytes);
        $metadata=app(MediaStorage::class)->inspectStored($relative,$id,'preview.jpg');
        return MediaAsset::create([...$metadata,'id'=>$id,'purpose'=>'about','alt'=>'Draft preview image','state'=>$state,'rights_scope'=>$rightsScope,'crop'=>['x'=>50,'y'=>50]]);
    }

    private function tree(string $root): array
    {
        if(!is_dir($root))return [];$out=[];foreach(File::allFiles($root) as $file)$out[str_replace('\\','/',$file->getRelativePathname())]=hash_file('sha256',$file->getPathname());ksort($out);return $out;
    }
}
