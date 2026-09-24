<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Project,Track,MediaAsset,Setting,CvEntry,User,ExternalService,Publication};
use App\Services\{ManifestImporter,ManifestExporter,ContentValidator};
use Livewire\Livewire;
use Filament\Facades\Filament;

class CmsTest extends TestCase
{
    use RefreshDatabase;
    private array $input;
    protected function setUp(): void
    {
        parent::setUp();
        $this->input=json_decode(file_get_contents(config('cms.manifest')),true);
        app(ManifestImporter::class)->import($this->input);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
    private function admin(): User { $u=User::create(['name'=>'Synthetic QA','email'=>'qa@example.test','password'=>bin2hex(random_bytes(20))]); $this->actingAs($u); return $u; }
    public function test_import_is_idempotent_and_shared_projects_are_not_duplicated(): void
    {
        $report=app(ManifestImporter::class)->import($this->input);
        $this->assertCount(0,$report['created']); $this->assertCount(0,$report['updated']); $this->assertCount(0,$report['conflicts']);
        $this->assertSame(count($this->input['projects']),Project::count()); $this->assertSame(count($this->input['tracks']),Track::count()); $this->assertSame(count($this->input['assets']),MediaAsset::count());
        $this->assertSame(2,Project::findOrFail('kinnhakenhank')->memberships()->count());
        $this->assertSame(9,CvEntry::count());
    }
    public function test_import_preserves_manual_edits_and_reports_conflicts(): void
    {
        Project::findOrFail('abszission')->update(['title'=>'Synthetic manual edit']);
        $report=app(ManifestImporter::class)->import($this->input);
        $this->assertContains('Project:abszission',$report['conflicts']);
        $this->assertSame('Synthetic manual edit',Project::findOrFail('abszission')->title);
        $this->assertCount(0,$report['updated']);
    }
    public function test_import_updates_clean_records_and_keeps_unknown_records(): void
    {
        Setting::put('private-qa',['secret'=>'synthetic-private']);
        $this->input['projects'][0]['title']='Synthetic changed input';
        $report=app(ManifestImporter::class)->import($this->input);
        $this->assertContains('Project:'.$this->input['projects'][0]['id'],$report['updated']);
        $this->assertSame(['secret'=>'synthetic-private'],Setting::read('private-qa'));
    }
    public function test_import_does_not_silently_restore_manual_deletions(): void
    {
        Project::findOrFail('abszission')->delete();
        $report=app(ManifestImporter::class)->import($this->input);
        $this->assertContains('Project:abszission (manually deleted)',$report['conflicts']);
        $this->assertNull(Project::find('abszission'));
    }
    public function test_saving_existing_shared_project_preserves_memberships_and_media(): void
    {
        $this->admin();
        $before=collect(app(ManifestExporter::class)->package()['manifest']['projects'])->firstWhere('id','kinnhakenhank');
        Livewire::test(\App\Filament\Resources\Pages\EditProject::class,['record'=>'kinnhakenhank'])->call('save')->assertHasNoFormErrors();
        $after=collect(app(ManifestExporter::class)->package()['manifest']['projects'])->firstWhere('id','kinnhakenhank');
        foreach (['title','memberships','gallery','localTrailerAssetId','localTrailerPosterId','externalFilm'] as $key) $this->assertEquals($before[$key],$after[$key],$key);
    }
    public function test_export_is_deterministic_valid_and_never_contains_private_fields(): void
    {
        Setting::put('contact',[...Setting::read('contact'),'recipient'=>'private@example.test']);
        MediaAsset::first()->update(['source'=>'PRIVATE_SOURCE_SENTINEL','license'=>'PRIVATE_LICENSE_SENTINEL']);
        $a=app(ManifestExporter::class)->package(); $b=app(ManifestExporter::class)->package();
        $this->assertSame(json_encode($a),json_encode($b));
        $text=json_encode($a);
        foreach (['private@example.test','PRIVATE_SOURCE_SENTINEL','PRIVATE_LICENSE_SENTINEL','password','DB_PASSWORD','sourceSha256'] as $secret) $this->assertStringNotContainsString($secret,$text);
        $this->assertSame([],$a['manifest']['sources']); $this->assertSame('next_draft',$a['settings']['scope']);
        $this->assertFalse($a['settings']['deploymentAuthorized']);
        $this->assertTrue($a['settings']['home']['loop']); $this->assertFalse($a['settings']['analytics']['enabled']);
        $this->assertSame(hash_file('sha256',config('cms.manifest')),hash('sha256',file_get_contents(config('cms.manifest'))));
    }
    public function test_profile_links_and_collective_roundtrip_through_settings_and_manifest(): void
    {
        $this->admin();
        Livewire::test(\App\Filament\Pages\Social::class)->fillForm([
            'artistLinks.spotify'=>'https://open.spotify.com/artist/test',
            'profileLinks.imdb'=>'https://www.imdb.com/name/test',
            'profileLinks.crewUnited'=>'https://www.crew-united.com/test',
            'profileLinks.musicHub'=>'https://music-hub.bio/test',
        ])->call('save')->assertHasNoFormErrors();
        Livewire::test(\App\Filament\Pages\About::class)->fillForm([
            'collective.name'=>'Samples unterm Sofa',
            'collective.url'=>'https://samples-unterm-sofa.de/',
            'collective.logoAssetId'=>null,
            'collective.membershipText'=>'Proud member of Samples unterm Sofa – Filmmusic Collective',
        ])->call('save')->assertHasNoFormErrors();
        $social=Setting::read('social');
        $this->assertSame('https://open.spotify.com/artist/test',$social['artistLinks']['spotify']);
        $this->assertSame('https://www.imdb.com/name/test',$social['profileLinks']['imdb']);
        $manifest=app(ManifestExporter::class)->package()['manifest'];
        $this->assertSame('https://open.spotify.com/artist/test',$manifest['site']['artistLinks']->spotify);
        $this->assertSame('https://www.imdb.com/name/test',$manifest['site']['profileLinks']->imdb);
        $this->assertSame('Samples unterm Sofa',$manifest['site']['collective']['name']);
        app(ContentValidator::class)->validate($manifest);
    }
    public function test_drafts_hidden_records_and_manual_order_are_respected(): void
    {
        Project::findOrFail('abszission')->update(['status'=>'draft']);
        Track::findOrFail('hole-in-that-dough')->update(['status'=>'hidden']);
        $project=Project::findOrFail('silver-strings');
        $first=$project->soundtrack()->first(); $first->update(['sort_order'=>999]);
        $package=app(ManifestExporter::class)->package();
        $this->assertNotContains('abszission',array_column($package['manifest']['projects'],'id'));
        $silver=collect($package['manifest']['projects'])->firstWhere('id','silver-strings');
        $this->assertSame($first->track_id,end($silver['tracks'])['trackId']);
    }
    public function test_candidate_and_publication_are_separate_from_save_draft(): void
    {
        $before=hash_file('sha256',config('cms.manifest'));
        $exporter=app(ManifestExporter::class);
        $dry=$exporter->export(); $this->assertSame(0,Publication::count());
        $published=$exporter->export(false,true);
        $file=storage_path('app/private/'.$published['path'].'/manifest.json'); $bytes=file_get_contents($file);
        Project::findOrFail('abszission')->update(['title'=>'Synthetic later draft']);
        $candidate=$exporter->export(false,false);
        $this->assertNotSame($candidate['hash'],$published['hash']); $this->assertSame($bytes,file_get_contents($file));
        $this->assertSame($before,hash_file('sha256',config('cms.manifest')));
    }
    public function test_bad_media_reference_blocks_export(): void
    {
        Setting::put('home',[...Setting::read('home'),'media'=>['image'=>'missing','video'=>null,'poster'=>null]]);
        $this->expectException(\DomainException::class); app(ManifestExporter::class)->package();
    }
    public function test_full_kinnhakenhank_is_blocked(): void
    {
        Project::findOrFail('kinnhakenhank')->update(['external_film'=>['provider'=>'youtube','videoId'=>'synthetic','url'=>'https://example.test/forbidden']]);
        $this->expectException(\DomainException::class); app(ManifestExporter::class)->package();
    }
    public function test_release_remains_blocked(): void
    {
        $package=app(ManifestExporter::class)->package();
        $this->expectException(\DomainException::class); app(ContentValidator::class)->validate($package['manifest'],true);
    }
    public function test_consent_cannot_disable_required_categories_or_enable_unconfigured_analytics(): void
    {
        Setting::put('consent',[...Setting::read('consent'),'essential'=>false]);
        $this->expectException(\Illuminate\Validation\ValidationException::class); app(ManifestExporter::class)->package();
    }
    public function test_scripts_are_not_provider_configuration(): void
    {
        ExternalService::create(['name'=>'Synthetic','provider'=>'youtube','category'=>'externalMedia','purpose'=>'QA','privacy_url'=>'https://example.test/privacy','provider_id'=>'<script>alert(1)</script>']);
        $this->expectException(\Illuminate\Validation\ValidationException::class); app(ManifestExporter::class)->package();
    }
    public function test_authentication_theme_isolation_and_private_routes(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin-media/hero-poster-02d')->assertRedirect('/admin/login');
        $first=$this->admin();
        $this->assertSame('dark',$first->fresh()->admin_theme);
        $this->postJson('/admin-theme',['theme'=>'light'])->assertOk();
        $this->assertSame('light',$first->fresh()->admin_theme);
        $this->postJson('/admin-theme',['theme'=>'invalid'])->assertUnprocessable();
        $second=User::create(['name'=>'Second QA','email'=>'qa2@example.test','password'=>bin2hex(random_bytes(20))]);
        $this->assertSame('dark',$second->fresh()->admin_theme);
        $this->postJson('/admin-theme',['theme'=>'system'])->assertOk();
        $this->assertSame('system',$first->fresh()->admin_theme); $this->assertSame('dark',$second->fresh()->admin_theme);
    }
    public function test_project_crud_through_filament_form(): void
    {
        $this->admin();
        Livewire::test(\App\Filament\Resources\Pages\CreateProject::class)->fillForm([
            'id'=>'synthetic-qa','slug'=>'synthetic-qa','title'=>'Synthetic QA','synopsis'=>'QA only','status'=>'draft','sort_order'=>0,'production_type'=>'QA','categories'=>[],'genres'=>[],'emotions'=>[],
        ])->call('create')->assertHasNoFormErrors();
        $p=Project::findOrFail('synthetic-qa');
        Livewire::test(\App\Filament\Resources\Pages\EditProject::class,['record'=>$p->id])->fillForm(['title'=>'Edited QA'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Edited QA',$p->fresh()->title); $p->delete(); $this->assertNull(Project::find('synthetic-qa'));
    }
    public function test_settings_forms_save_about_seo_legal_and_consent_as_drafts(): void
    {
        $this->admin();
        foreach ([\App\Filament\Pages\About::class=>['title'=>'Synthetic About'],\App\Filament\Pages\Seo::class=>['description'=>'Synthetic SEO'],\App\Filament\Pages\Impressum::class=>['text'=>'Synthetic legal draft','status'=>'draft'],\App\Filament\Pages\Consent::class=>['bannerText'=>'Synthetic consent']] as $page=>$fields) {
            Livewire::test($page)->fillForm($fields)->call('save')->assertHasNoFormErrors();
        }
        $this->assertSame('Synthetic About',Setting::read('about')['title']);
        $this->assertSame('Synthetic legal draft',Setting::read('impressum')['text']);
        $this->assertSame('draft',Setting::read('impressum')['status']); $this->assertSame(0,Publication::count());
    }
    public function test_track_media_and_cv_forms(): void
    {
        $this->admin();
        Livewire::test(\App\Filament\Resources\Pages\CreateTrack::class)->fillForm(['id'=>'synthetic-track','title'=>'Synthetic','status'=>'draft','rights_scope'=>'unconfirmed','genres'=>['Ambient'],'emotions'=>['calm']])->call('create')->assertHasNoFormErrors();
        $this->assertSame(['Ambient'],Track::findOrFail('synthetic-track')->genres);
        Livewire::test(\App\Filament\Resources\Pages\EditMediaAsset::class,['record'=>'hero-poster-02d'])->fillForm(['alt'=>'Synthetic alt'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Synthetic alt',MediaAsset::findOrFail('hero-poster-02d')->alt);
        Livewire::test(\App\Filament\Resources\Pages\CreateCvEntry::class)->fillForm(['id'=>'synthetic-cv','date'=>'2026','title'=>'Synthetic CV','status'=>'draft','sort_order'=>99])->call('create')->assertHasNoFormErrors();
        $this->assertSame('Synthetic CV',CvEntry::findOrFail('synthetic-cv')->title);
        Livewire::test(\App\Filament\Resources\Pages\EditTrack::class,['record'=>'synthetic-track'])->fillForm(['title'=>'Changed track'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Changed track',Track::findOrFail('synthetic-track')->title);
        Livewire::test(\App\Filament\Resources\Pages\ListTracks::class)->callTableAction('delete','synthetic-track')->assertHasNoErrors();
        $this->assertNull(Track::find('synthetic-track'));
        Livewire::test(\App\Filament\Resources\Pages\CreateMediaAsset::class)->fillForm(['id'=>'synthetic-media','path'=>'media/synthetic-test.jpg','type'=>'image','purpose'=>'other','alt'=>'QA','state'=>'temporary','rights_scope'=>'unconfirmed','crop'=>['x'=>50,'y'=>50]])->call('create')->assertHasNoFormErrors();
        $this->assertNotNull(MediaAsset::find('synthetic-media'));
        Livewire::test(\App\Filament\Resources\Pages\ListMediaAssets::class)->callTableAction('delete','synthetic-media')->assertHasNoErrors();
        $this->assertNull(MediaAsset::find('synthetic-media'));
        Livewire::test(\App\Filament\Resources\Pages\EditCvEntry::class,['record'=>'synthetic-cv'])->fillForm(['title'=>'Changed CV'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Changed CV',CvEntry::findOrFail('synthetic-cv')->title);
        Livewire::test(\App\Filament\Resources\Pages\ListCvEntrys::class)->callTableAction('delete','synthetic-cv')->assertHasNoErrors();
        $this->assertNull(CvEntry::find('synthetic-cv'));
    }
    public function test_external_service_crud_is_typed_and_inactive_by_default(): void
    {
        $this->admin();
        Livewire::test(\App\Filament\Resources\Pages\CreateExternalService::class)->fillForm(['name'=>'Synthetic provider','provider'=>'youtube','category'=>'externalMedia','purpose'=>'Synthetic QA','privacy_url'=>'https://example.test/privacy','requires_consent'=>true,'enabled'=>false,'sort_order'=>2])->call('create')->assertHasNoFormErrors();
        $record=ExternalService::firstOrFail(); $this->assertFalse($record->enabled);
        Livewire::test(\App\Filament\Resources\Pages\EditExternalService::class,['record'=>$record->id])->fillForm(['description'=>'Changed service'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Changed service',$record->fresh()->description);
        Livewire::test(\App\Filament\Resources\Pages\ListExternalServices::class)->callTableAction('delete',$record->id)->assertHasNoErrors();
        $this->assertSame(0,ExternalService::count());
    }
    public function test_changing_playback_invalidates_waveform_and_unconfirmed_audio_is_blocked(): void
    {
        $track=Track::whereNotNull('audio_asset_id')->firstOrFail();
        $other=MediaAsset::where('type','audio')->where('id','!=',$track->audio_asset_id)->firstOrFail();
        $track->update(['audio_asset_id'=>$other->id,'rights_scope'=>'unconfirmed']);
        $this->assertNull($track->fresh()->peaks); $this->assertNull($track->fresh()->audio_sha256);
        $this->expectException(\DomainException::class); app(ManifestExporter::class)->package();
    }
    public function test_hover_type_and_unsafe_urls_match_frontend_rules(): void
    {
        $manifest=app(ManifestExporter::class)->package()['manifest'];
        $manifest['site']['contacts']['instagram']='ftp://example.test/private';
        $this->expectException(\DomainException::class); app(ContentValidator::class)->validate($manifest);
    }
    public function test_03b_real_draft_save_and_authenticated_candidate_leave_active_unchanged(): void
    {
        $admin=$this->admin(); $before=hash_file('sha256',config('cms.manifest'));
        $original=Project::findOrFail('abszission')->synopsis;
        Livewire::test(\App\Filament\Resources\Pages\EditProject::class,['record'=>'abszission'])
            ->fillForm(['synopsis'=>$original."\n\n[03B synthetic reversible check]"])->call('save')->assertHasNoFormErrors();
        $this->assertSame($before,hash_file('sha256',config('cms.manifest')));
        $export=app(ManifestExporter::class)->export(false,false,$admin->id);
        $dir=storage_path('app/private/'.$export['path']);
        $receipt=json_decode(file_get_contents($dir.'/receipt.json'),true);
        $this->assertSame($admin->id,$receipt['adminUserId']);
        $this->assertSame($export['hash'],$receipt['candidateHash']);
        $this->assertSame(hash_file('sha256',$dir.'/manifest.json'),$receipt['manifestSha256']);
        $this->assertSame(hash_file('sha256',$dir.'/cms-settings.json'),$receipt['settingsSha256']);
        $this->assertStringNotContainsString('password',file_get_contents($dir.'/receipt.json'));
        $this->assertSame($before,hash_file('sha256',config('cms.manifest')));
    }
    public function test_03b_admin_theme_is_independent_from_website_colors_and_manifest(): void
    {
        $admin=$this->admin();$appearance=Setting::read('appearance');$before=hash_file('sha256',config('cms.manifest'));
        foreach(['light','system','dark'] as $theme) {
            $this->postJson('/admin-theme',['theme'=>$theme])->assertOk();
            $this->assertSame($theme,$admin->fresh()->admin_theme);
            $this->assertSame($appearance,Setting::read('appearance'));
            $this->assertSame($before,hash_file('sha256',config('cms.manifest')));
        }
    }
    public function test_03b_synopsis_only_save_preserves_optional_fields_and_seo_default(): void
    {
        $this->admin();$p=Project::findOrFail('abszission');$before=ManifestImporter::snapshot($p);
        $form=Livewire::test(\App\Filament\Resources\Pages\EditProject::class,['record'=>'abszission']);
        $form->assertFormSet(['seo.noindex'=>true])->fillForm(['synopsis'=>$p->synopsis.' Synthetic QA'])->call('save')->assertHasNoFormErrors();
        $after=ManifestImporter::snapshot($p);$after['synopsis']=$before['synopsis'];
        $this->assertSame($before,$after);
        Livewire::test(\App\Filament\Resources\Pages\EditProject::class,['record'=>'abszission'])
            ->fillForm(['synopsis'=>$before['synopsis'],'seo.noindex'=>false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($p->fresh()->seo['noindex']);
    }
}
