<?php
namespace Tests\Feature;

use App\Filament\Pages\{Analytics,Consent,Dashboard,Help,Publication,Seo};
use App\Filament\Resources\{ExternalServiceResource,MediaAssetResource,ProjectResource};
use App\Models\{MediaAsset,Setting,User};
use App\Services\{ManifestExporter,ManifestImporter};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerExperienceTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        app(ManifestImporter::class)->import(json_decode(file_get_contents(config('cms.manifest')),true));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::create(['name'=>'Owner QA','email'=>'owner@example.test','password'=>bin2hex(random_bytes(20))]));
    }

    public function test_dashboard_renders_owner_cards_links_and_computed_warnings_without_developer_copy(): void
    {
        $seo=Setting::read('seo'); Setting::put('seo',[...$seo,'description'=>'','canonicalBase'=>null,'ogAssetId'=>null,'robots'=>'noindex, nofollow']);
        MediaAsset::where('type','image')->firstOrFail()->update(['alt'=>'']);
        Livewire::test(Dashboard::class)
            ->assertSee('Website-Status')->assertSee('Inhalte')->assertSee('SEO-Status')->assertSee('Analytics')
            ->assertSee('Website-Vorschau öffnen')->assertSee('Änderungen prüfen')
            ->assertSeeHtml('<strong>'.MediaAsset::count().'</strong><br>Medien in der Bibliothek')->assertSeeHtml('<strong>'.count(app(ManifestExporter::class)->package()['manifest']['assets']).'</strong> Medien im aktuellen Website-Paket')
            ->assertSee('Alternativtexte ergänzen')->assertSee('Suchmaschinen sind noch ausgeschlossen')->assertSee('Canonical')
            ->assertSeeHtml(ProjectResource::getUrl('create'))->assertSeeHtml(MediaAssetResource::getUrl('create'))
            ->assertDontSee('4321')->assertDontSee('Docker')->assertDontSee('Manifest')->assertDontSee('Plan-Hash')->assertDontSee('Au milieu')->assertDontSee('Kinnhakenhank');
    }

    public function test_consent_version_defaults_to_one_without_overwriting_saved_value_and_external_services_link_to_help(): void
    {
        $consent=Setting::read('consent');unset($consent['version']);Setting::put('consent',$consent);
        Livewire::test(Consent::class)->assertFormSet(['version'=>1])->assertSee('Nur erhöhen, wenn Besucher nach einer wesentlichen Änderung');
        $this->assertArrayNotHasKey('version',Setting::read('consent'),'Opening the form must not persist or overwrite settings.');
        Setting::put('consent',[...$consent,'version'=>7]);Livewire::test(Consent::class)->assertFormSet(['version'=>7]);
        $this->get(ExternalServiceResource::getUrl('index'))->assertOk()->assertSee('Hilfe')->assertSee('search=Externe%20Medien',false);
    }

    public function test_publication_explains_owner_flow_without_requiring_candidate_steps(): void
    {
        Livewire::test(Publication::class)->assertSee('Website-Vorschau öffnen')->assertSee('Änderungen prüfen')->assertSee('Die Änderungen wurden noch nicht geprüft')->assertSee('Technische Details')->assertDontSee('Vorschau-ID');
    }

    public function test_help_search_categories_empty_state_and_cms_links(): void
    {
        Livewire::test(Help::class)->set('search','Canonical')->assertSee('Canonical URL')->assertDontSee('Ein neues Bild hochladen')
            ->set('search','')->set('category','Medien')->assertSee('Ein neues Bild hochladen')->assertDontSee('SEO einfach erklärt')
            ->set('search','unauffindbarer-begriff')->assertSee('Keine passenden Artikel');
        $this->get(Help::getUrl())->assertOk()->assertSee('Im Handbuch suchen')->assertSee('Analytics mit einem KI-Assistenten auswerten');
        Livewire::test(Help::class)->set('category','Veröffentlichung')->assertSee('Entwurf speichern')->assertSee('Website-Vorschau öffnen')->assertSee('Unterschied Vorschau / Veröffentlichung')->assertSee('Was bedeutet „nicht mehr aktuell“?');
    }

    public function test_seo_page_explains_fields_and_warns_about_noindex(): void
    {
        Livewire::test(Seo::class)->assertSee('Suchmaschinen sind derzeit bewusst ausgeschlossen')
            ->assertSee('Bestimmt, wie Seitentitel')->assertSee('bevorzugte öffentliche Hauptadresse')->assertSee('Vorschaubild beim Teilen');
    }

    public function test_analytics_provider_validation_and_consent_warning(): void
    {
        Livewire::test(Analytics::class)->fillForm(['enabled'=>true,'provider'=>'none'])->call('save')->assertHasFormErrors(['provider']);
        Livewire::test(Analytics::class)->fillForm(['enabled'=>true,'provider'=>'ga4','measurementId'=>'invalid'])->call('save')->assertHasFormErrors(['measurementId']);
        Livewire::test(Analytics::class)->fillForm(['enabled'=>true,'provider'=>'plausible','siteDomain'=>'pianissimo.example'])->call('save')->assertHasNoFormErrors();
        $saved=Setting::read('analytics'); $this->assertSame('plausible',$saved['provider']); $this->assertSame('pianissimo.example',$saved['siteDomain']);
        Livewire::test(Analytics::class)->assertSee('Analyse-Einwilligung fehlt')->assertSee('Plausible Website-Domain');
        try{app(ManifestExporter::class)->package();$this->fail('Analytics without its consent category must not export.');}catch(\DomainException $e){$this->assertStringContainsString('Consent',$e->getMessage());}
        Setting::put('consent',[...Setting::read('consent'),'analytics'=>true,'version'=>2]);
        $manifest=app(ManifestExporter::class)->package()['manifest'];
        $this->assertSame('plausible',$manifest['site']['analytics']['provider']); $this->assertSame(2,$manifest['site']['consent']['version']);
        $this->assertArrayNotHasKey('password',$manifest['site']['analytics']);
    }
}
