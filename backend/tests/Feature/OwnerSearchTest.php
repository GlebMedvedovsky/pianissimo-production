<?php

namespace Tests\Feature;

use App\Filament\Pages\Help;
use App\Filament\Resources\ProjectResource;
use App\Models\{MediaAsset, Project};
use App\Services\{HelpCenter, ManifestImporter, OwnerGlobalSearchProvider, OwnerSearch};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ManifestImporter::class)->import(json_decode(file_get_contents(config('cms.manifest')), true));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_normalization_prefix_alias_multi_token_and_typo_matching(): void
    {
        $search = app(OwnerSearch::class);
        $entry = ['title' => 'Veröffentlichung', 'terms' => ['Website Vorschau'], 'aliases' => ['publish', 'veroeffentlichen']];
        $this->assertSame('aerger oel ueber ss', $search->normalize('Ärger Öl über ß'));
        $this->assertNotNull($search->score('VERÖFFENTLICHUNG', $entry));
        $this->assertNotNull($search->score('verofentlichen', $entry));
        $this->assertNotNull($search->score('veröffent', $entry));
        $this->assertNotNull($search->score('website vorschau', $entry));
        $this->assertNotNull($search->score('publish', $entry));
        $this->assertNull($search->score('passwort', $entry));
    }

    public function test_global_search_groups_results_ranks_exact_matches_and_uses_direct_urls(): void
    {
        $provider = app(OwnerGlobalSearchProvider::class);
        $seo = $provider->getResults('SEO')->getCategories();
        $this->assertTrue($seo->has('Website'));
        $this->assertSame('SEO', $seo['Website']->first()->title);

        $project = Project::query()->where('title', 'like', '%Kinnhakenhank%')->firstOrFail();
        $results = $provider->getResults('Kinnhakenhank')->getCategories();
        $this->assertTrue($results->has('Inhalte'));
        $match = $results['Inhalte']->first(fn ($result) => str_contains((string) $result->title, 'Kinnhakenhank'));
        $this->assertNotNull($match);
        $this->assertSame(ProjectResource::getUrl('edit', ['record' => $project]), $match->url);

        $this->assertTrue($provider->getResults('bild about')->getCategories()->has('Hilfe / Handbuch'));
        $this->assertTrue($provider->getResults('galeri')->getCategories()->has('Hilfe / Handbuch'));
        $this->assertTrue($provider->getResults('analytycs')->getCategories()->has('Website'));
        $this->assertTrue($provider->getResults('suchmachine')->getCategories()->has('Website'));
    }

    public function test_help_search_is_help_only_and_supports_fuzzy_terms(): void
    {
        $help = app(HelpCenter::class);
        $this->assertCount(73, $help->articles());
        $this->assertContains('Jetzt öffentlich veröffentlichen', array_column($help->articles(), 'title'));
        $this->assertContains('Galerie sortieren', array_column($help->search('galeri'), 'title'));
        $this->assertContains('Große Dateien per SFTP hochladen', array_column($help->search('sftp'), 'title'));
        $this->assertNotEmpty($help->search('verofentlichen'));
        $this->assertSame([], $help->search('Au milieu'));
        $this->assertStringContainsString('/admin/help', Help::getUrl());
    }

    public function test_private_and_internal_fields_are_not_searchable(): void
    {
        $asset = MediaAsset::query()->firstOrFail();
        $asset->update(['source' => 'private-secret-needle', 'license' => 'private-license-needle']);
        $provider = app(OwnerGlobalSearchProvider::class);
        $this->assertTrue($provider->getResults('private-secret-needle')->getCategories()->isEmpty());
        $this->assertTrue($provider->getResults('private-license-needle')->getCategories()->isEmpty());
        $this->assertTrue($provider->getResults('app.ts')->getCategories()->isEmpty());
        $this->assertTrue($provider->getResults('Docker')->getCategories()->isEmpty());
    }
}
