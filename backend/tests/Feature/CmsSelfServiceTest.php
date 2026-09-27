<?php
namespace Tests\Feature;

use App\Models\{AuditEvent,MediaAsset,Membership,Project,Publication,Setting,User};
use App\Services\{DraftStateSnapshot,FrontendPublication,ManifestExporter,ManifestImporter,MediaStorage,MediaUsageResolver};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CmsSelfServiceTest extends TestCase
{
    use RefreshDatabase;
    private string $fixtureRoot;
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->input=json_decode(file_get_contents(config('cms.manifest')),true);
        app(ManifestImporter::class)->import($this->input);
        $this->fixtureRoot=storage_path('app/private/self-service-tests/'.getmypid().'-'.bin2hex(random_bytes(4)));
        mkdir($this->fixtureRoot.'/managed',0750,true);
        config(['cms.export_prefix'=>'self-service-tests/'.basename($this->fixtureRoot).'/exports']);
    }
    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot)) \Illuminate\Support\Facades\File::deleteDirectory($this->fixtureRoot);
        Storage::disk('local')->deleteDirectory('media/originals/qa-upload');
        Storage::disk('local')->deleteDirectory('media/originals/qa-roundtrip');
        parent::tearDown();
    }
    private function admin(): User
    {
        $user=User::create(['name'=>'Self Service QA','email'=>bin2hex(random_bytes(4)).'@example.test','password'=>bin2hex(random_bytes(20))]);
        $this->actingAs($user); return $user;
    }
    private function jpg(string $id): MediaAsset
    {
        $bytes=base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==');
        $relative='media/originals/'.$id.'/upload.jpg'; Storage::disk('local')->put($relative,$bytes);
        $metadata=app(MediaStorage::class)->inspectStored($relative,$id,'portrait.jpg');
        return MediaAsset::create([...$metadata,'id'=>$id,'purpose'=>'about','alt'=>'QA portrait','state'=>'temporary','rights_scope'=>'next_draft','crop'=>['x'=>50,'y'=>50]]);
    }

    public function test_jpg_upload_calculates_metadata_without_changing_frontend(): void
    {
        $before=hash_file('sha256',config('cms.manifest')); $asset=$this->jpg('qa-upload');
        $this->assertSame('image/jpeg',$asset->mime_type); $this->assertSame('image',$asset->type);
        $this->assertSame(1,$asset->width); $this->assertSame(1,$asset->height); $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$asset->sha256);
        $this->assertSame($before,hash_file('sha256',config('cms.manifest')));
        $this->assertTrue(MediaAsset::whereKey($asset->id)->where('type','image')->exists());
        $this->assertFalse(MediaAsset::whereKey($asset->id)->where('type','video')->exists());
    }

    public function test_usage_resolution_detach_required_guard_and_transactional_replace(): void
    {
        $source=MediaAsset::where('type','image')->firstOrFail(); $replacement=MediaAsset::where('type','image')->whereKeyNot($source->id)->firstOrFail();
        Setting::put('about',[...Setting::read('about'),'media'=>['image'=>$source->id,'video'=>null,'poster'=>$source->id]]);
        $membership=Membership::first(); $membership->update(['cover_asset_id'=>$source->id]);
        $service=app(MediaUsageResolver::class); $usages=$service->usages($source);
        $this->assertTrue(collect($usages)->contains(fn($u)=>$u['location']==='About' && $u['slot']==='Bild'));
        $this->expectException(\DomainException::class);
        try {$service->detach($source,array_column($usages,'key'));}
        finally {$this->assertSame($source->id,$membership->fresh()->cover_asset_id);}
    }

    public function test_bulk_replace_three_usages_is_all_or_nothing(): void
    {
        $source=MediaAsset::where('type','image')->firstOrFail(); $replacement=MediaAsset::where('type','image')->whereKeyNot($source->id)->firstOrFail();
        foreach(['home','about','music'] as $key)Setting::put($key,[...Setting::read($key),'media'=>['image'=>$source->id,'video'=>null,'poster'=>null]]);
        $service=app(MediaUsageResolver::class); $keys=array_values(array_filter(array_column($service->usages($source),'key'),fn($key)=>str_starts_with($key,'setting:'))); $service->replace($source,$replacement,$keys);
        foreach(['home','about','music'] as $key)$this->assertSame($replacement->id,Setting::read($key)['media']['image']);
        $video=MediaAsset::where('type','video')->firstOrFail();
        foreach(['home','about','music'] as $key)Setting::put($key,[...Setting::read($key),'media'=>['image'=>$source->id,'video'=>null,'poster'=>null]]);
        try{$service->replace($source,$video,array_column($service->usages($source),'key'));$this->fail('Compatibility must fail');}catch(\DomainException){}
        foreach(['home','about','music'] as $key)$this->assertSame($source->id,Setting::read($key)['media']['image']);
    }

    public function test_dynamic_social_links_are_ordered_hidden_and_html_safe(): void
    {
        Setting::put('social',['links'=>[
            ['provider'=>'vimeo','label'=>'Vimeo','url'=>'https://vimeo.com/example','visible'=>true,'sort_order'=>2],
            ['provider'=>'spotify','label'=>'Spotify','url'=>'https://open.spotify.com/artist/example','visible'=>false,'sort_order'=>1],
            ['provider'=>'other','label'=>'Elsewhere','url'=>'https://example.test/profile','visible'=>true,'sort_order'=>3],
        ]]);
        $links=app(ManifestExporter::class)->package()['manifest']['site']['socialLinks'];
        $this->assertSame(['vimeo','spotify','other'],array_column($links,'provider'));
        $this->assertFalse($links[1]['visible']); $this->assertStringNotContainsString('<svg',json_encode($links));
        Setting::put('social',['links'=>[['provider'=>'other','label'=>'Bad','url'=>'javascript:alert(1)','visible'=>true,'sort_order'=>0]]]);
        $this->expectException(\DomainException::class); app(ManifestExporter::class)->package();
    }

    public function test_social_page_audits_add_and_remove_without_urls(): void
    {
        $this->admin();
        Setting::put('social',['links'=>[['provider'=>'spotify','label'=>'Spotify','url'=>'https://open.spotify.com/artist/test','visible'=>true,'sort_order'=>0]]]);
        Livewire::test(\App\Filament\Pages\Social::class)->fillForm(['links'=>[
            ['provider'=>'vimeo','label'=>'Vimeo','url'=>'https://vimeo.com/test','visible'=>true,'sort_order'=>0],
        ]])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(AuditEvent::where('object_type','settings')->where('object_id','social')->where('action','add')->exists());
        $this->assertTrue(AuditEvent::where('object_type','settings')->where('object_id','social')->where('action','remove')->exists());
        $this->assertStringNotContainsString('https://',AuditEvent::where('object_id','social')->get()->toJson());
    }

    public function test_candidate_apply_and_rollback_restore_manifest_and_media_bytes(): void
    {
        $admin=$this->admin(); $active=$this->fixtureRoot.'/manifest.json'; copy(config('cms.manifest'),$active);
        config(['cms.manifest'=>$active,'cms.managed_media_root'=>$this->fixtureRoot.'/managed','cms.frontend_apply_state'=>$this->fixtureRoot.'/state']);
        $before=file_get_contents($active); $asset=$this->jpg('qa-roundtrip');
        Setting::put('about',[...Setting::read('about'),'media'=>[...Setting::read('about')['media'],'image'=>$asset->id]]);
        $this->assertSame($before,file_get_contents($active));
        $export=app(ManifestExporter::class)->export(false,false,$admin->id); $candidate=storage_path('app/private/'.config('cms.export_prefix').'/candidates/'.$export['hash'].'/manifest.json');
        $this->assertSame(config('cms.export_prefix').'/candidates/'.$export['hash'],$export['path']);
        $this->assertFileExists($candidate,'Candidate hash '.$export['hash'].' must resolve to its manifest (returned path '.$export['path'].').'); $this->assertStringContainsString('qa-roundtrip',file_get_contents($candidate));
        $review=app(FrontendPublication::class)->review($export['hash'],$admin->id);
        $this->assertGreaterThan(0,$review['changeCount']); $this->assertFileDoesNotExist($this->fixtureRoot.'/managed/'.basename($asset->path));
        $this->assertFalse(collect($review['descriptions'])->contains(fn($description)=>str_contains($description,'externalLinks')));
        $applied=app(FrontendPublication::class)->apply($review['planHash'],$admin->id); $this->assertSame('success',$applied['status']);
        $managed=$this->fixtureRoot.'/managed/'.basename($asset->path); $this->assertSame($asset->sha256,hash_file('sha256',$managed)); $this->assertNotSame($before,file_get_contents($active));
        $this->assertSame('already-current',app(FrontendPublication::class)->apply($review['planHash'],$admin->id)['status']);
        $firstApplied=file_get_contents($active); $firstManaged=$managed; $firstSha=$asset->sha256;
        $replacement='media/originals/'.$asset->id.'/replacement.png'; Storage::disk('local')->put($replacement,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII='));
        app(MediaStorage::class)->replace($asset,$replacement,'replacement.png'); $asset->refresh();
        $this->assertSame($firstApplied,file_get_contents($active)); $this->assertSame($firstSha,hash_file('sha256',$firstManaged));
        $secondExport=app(ManifestExporter::class)->export(false,false,$admin->id); $secondReview=app(FrontendPublication::class)->review($secondExport['hash'],$admin->id); app(FrontendPublication::class)->apply($secondReview['planHash'],$admin->id);
        $secondManaged=$this->fixtureRoot.'/managed/'.basename($asset->path); $this->assertSame($asset->sha256,hash_file('sha256',$secondManaged)); $this->assertFileDoesNotExist($firstManaged);
        $rollback=app(FrontendPublication::class)->rollbackReview($admin->id); app(FrontendPublication::class)->rollback($rollback['planHash'],$admin->id);
        $this->assertSame($firstApplied,file_get_contents($active)); $this->assertSame($firstSha,hash_file('sha256',$firstManaged)); $this->assertFileDoesNotExist($secondManaged);
        $rollback=app(FrontendPublication::class)->rollbackReview($admin->id); app(FrontendPublication::class)->rollback($rollback['planHash'],$admin->id);
        $this->assertSame($before,file_get_contents($active)); $this->assertFileDoesNotExist($managed);
    }

    public function test_apply_guards_invalid_unreviewed_stale_concurrent_and_missing_rollback(): void
    {
        $admin=$this->admin(); $active=$this->fixtureRoot.'/manifest.json'; copy(config('cms.manifest'),$active);
        config(['cms.manifest'=>$active,'cms.managed_media_root'=>$this->fixtureRoot.'/managed','cms.frontend_apply_state'=>$this->fixtureRoot.'/state']);
        $flow=app(FrontendPublication::class);
        foreach(['invalid',str_repeat('a',64)] as $hash)try{$flow->apply($hash,$admin->id);$this->fail('Unreviewed/invalid apply must fail');}catch(\DomainException){}
        try{$flow->rollbackReview($admin->id);$this->fail('Rollback without revision must fail');}catch(\DomainException){}
        Setting::put('social',['links'=>[['provider'=>'vimeo','label'=>'Vimeo','url'=>'https://vimeo.com/test','visible'=>true,'sort_order'=>0]]]);
        $export=app(ManifestExporter::class)->export(false,false,$admin->id); $this->assertFileExists(storage_path('app/private/'.config('cms.export_prefix').'/candidates/'.$export['hash'].'/manifest.json')); $review=$flow->review($export['hash'],$admin->id);
        file_put_contents($active,"\n",FILE_APPEND); try{$flow->apply($review['planHash'],$admin->id);$this->fail('Stale baseline must fail');}catch(\DomainException){}
        file_put_contents($active,substr(file_get_contents($active),0,-1));
        if(!is_dir($this->fixtureRoot.'/state'))mkdir($this->fixtureRoot.'/state',0750,true); file_put_contents($this->fixtureRoot.'/state/operation.lock','999');
        try{$flow->apply($review['planHash'],$admin->id);$this->fail('Concurrent apply must fail');}catch(\DomainException){} finally {unlink($this->fixtureRoot.'/state/operation.lock');}
    }

    public function test_unpublished_draft_can_be_discarded_without_changing_frontend_and_stales_candidate(): void
    {
        $admin=$this->admin();$active=$this->fixtureRoot.'/manifest.json';copy(config('cms.manifest'),$active);
        config(['cms.manifest'=>$active,'cms.managed_media_root'=>$this->fixtureRoot.'/managed','cms.frontend_apply_state'=>$this->fixtureRoot.'/state']);
        $project=Project::where('status','published')->firstOrFail();$appliedSynopsis=$project->synopsis.' Applied baseline';$project->update(['synopsis'=>$appliedSynopsis]);
        $flow=app(FrontendPublication::class);$first=app(ManifestExporter::class)->export(false,false,$admin->id);$firstReview=$flow->review($first['hash'],$admin->id);$flow->apply($firstReview['planHash'],$admin->id);
        $this->assertFileExists($this->fixtureRoot.'/state/applied-cms.json');$frontend=file_get_contents($active);

        $project->update(['synopsis'=>'Dieser nicht veröffentlichte Text soll verworfen werden.']);
        $second=app(ManifestExporter::class)->export(false,false,$admin->id);$secondReview=$flow->review($second['hash'],$admin->id);
        $draftPreview=app(\App\Services\DraftPreviewService::class)->create($admin->id);
        $draft=app(DraftStateSnapshot::class);$this->assertTrue($draft->hasChanges());$this->assertStringContainsString('Projekte',implode(' ',$draft->preview()['descriptions']));
        Livewire::test(\App\Filament\Pages\Publication::class)->assertSee('Nicht veröffentlichte Änderungen verwerfen');

        $result=$draft->discard($admin->id);
        $this->assertSame($appliedSynopsis,Project::findOrFail($project->id)->synopsis);
        $this->assertSame($frontend,file_get_contents($active),'Draft discard must never change the frontend manifest.');
        $this->assertGreaterThanOrEqual(1,$result['staleCandidates']);
        $this->assertSame('stale',Publication::where('hash',$second['hash'])->firstOrFail()->status);
        $this->assertTrue(AuditEvent::where('object_type','publication')->where('action','draft-discard')->exists());
        try{app(\App\Services\DraftPreviewService::class)->resolve($draftPreview['token']);$this->fail('Draft discard must invalidate website preview snapshots.');}catch(\DomainException){}
        $this->assertFalse($draft->hasChanges());
        Project::findOrFail($project->id)->update(['synopsis'=>'Dieser nicht veröffentlichte Text soll verworfen werden.']);
        $fresh=app(ManifestExporter::class)->export(false,false,$admin->id);$this->assertSame($second['hash'],$fresh['hash']);$freshReview=$flow->review($fresh['hash'],$admin->id);$this->assertNotSame($secondReview['planHash'],$freshReview['planHash']);
        try{$flow->apply($secondReview['planHash'],$admin->id);$this->fail('A discarded candidate must not apply.');}catch(\DomainException $e){$this->assertStringContainsString('veraltet',$e->getMessage());}
    }

    public function test_existing_installation_without_apply_audit_detects_and_discards_about_draft_from_active_frontend(): void
    {
        $admin=$this->admin();$active=$this->fixtureRoot.'/manifest.json';copy(config('cms.manifest'),$active);
        config(['cms.manifest'=>$active,'cms.frontend_apply_state'=>$this->fixtureRoot.'/state']);
        $applied=json_decode(file_get_contents($active),true)['site']['biography'];$about=Setting::read('about');Setting::put('about',[...$about,'biography'=>[...$applied,'Nicht veröffentlichter Browser-Entwurf']]);
        $frontend=file_get_contents($active);$draft=app(DraftStateSnapshot::class);
        $this->assertTrue($draft->hasChanges());$this->assertStringContainsString('Website-Einstellungen',implode(' ',$draft->preview()['descriptions']));
        Livewire::test(\App\Filament\Pages\Publication::class)->assertSee('Nicht veröffentlichte Änderungen verwerfen')->assertSee('Noch keine aktuelle Vorschau vorhanden');
        $draft->discard($admin->id);
        $this->assertSame($applied,Setting::read('about')['biography']);$this->assertSame($frontend,file_get_contents($active));$this->assertFalse($draft->hasChanges());
    }
}
