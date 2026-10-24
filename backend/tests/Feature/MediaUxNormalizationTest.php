<?php

namespace Tests\Feature;

use App\Filament\Fields;
use App\Filament\Pages\About;
use App\Models\{MediaAsset, Project, Setting, Track};
use App\Services\{ContentValidator, ManifestExporter, ManifestImporter, MediaUsageResolver, SettingsExporter};
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MediaUxNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ManifestImporter::class)->import(json_decode(file_get_contents(config('cms.manifest')), true));
    }

    public function test_every_media_role_has_explicit_projection_order_and_type_safety(): void
    {
        $images = MediaAsset::where('type', 'image')->take(3)->get();
        $imageA = $images->get(0);
        $imageB = $images->get(1);
        $imageC = $images->get(2);
        $video = MediaAsset::where('type', 'video')->firstOrFail();
        $audio = MediaAsset::where('type', 'audio')->firstOrFail();

        $about = Setting::read('about');
        Setting::put('about', [...$about, 'media' => [
            'mode' => 'image',
            'image' => $imageA->id,
            'video' => $video->id,
            'poster' => $imageB->id,
        ]]);
        Livewire::test(About::class)->assertFormSet(['media.mode' => 'image'])->fillForm([
            'media.mode' => 'video',
            'media.video' => $video->id,
            'media.poster' => $imageB->id,
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame('video', Setting::read('about')['media']['mode']);
        $videoProjection = app(ManifestExporter::class)->package(ContentValidator::POLICY_DRAFT_PREVIEW)['manifest']['site']['mediaSlots']['about'];
        $this->assertNull($videoProjection['image']);
        $this->assertSame($video->id, $videoProjection['video']);
        $this->assertSame($imageB->id, $videoProjection['poster']);

        $saved = Setting::read('about');
        $saved['media']['mode'] = 'image';
        Setting::put('about', $saved);
        $imageProjection = app(ManifestExporter::class)->package(ContentValidator::POLICY_DRAFT_PREVIEW)['manifest']['site']['mediaSlots']['about'];
        $this->assertSame($imageA->id, $imageProjection['image']);
        $this->assertNull($imageProjection['video']);
        $this->assertNull($imageProjection['poster']);

        $project = Project::create([
            'id' => 'qa-media-roles',
            'slug' => 'qa-media-roles',
            'title' => 'QA media roles',
            'synopsis' => 'Synthetic media-role check.',
            'status' => 'published',
            'sort_order' => 999,
            'production_type' => 'QA',
            'credits' => [],
            'categories' => [],
            'awards' => [],
            'platform_links' => [],
            'genres' => [],
            'emotions' => [],
            'seo' => ['ogAssetId' => $imageC->id],
            'external_links' => [],
        ]);
        $project->memberships()->create([
            'direction' => 'music',
            'role' => 'Composer',
            'sort_order' => 0,
            'cover_asset_id' => $imageA->id,
            'hover_asset_id' => $video->id,
            'crop' => ['x' => 50, 'y' => 50],
        ]);
        $project->mediaAssignments()->createMany([
            ['media_asset_id' => $imageA->id, 'slot' => 'gallery', 'sort_order' => 20],
            ['media_asset_id' => $imageB->id, 'slot' => 'gallery', 'sort_order' => 10],
            ['media_asset_id' => $video->id, 'slot' => 'trailer', 'sort_order' => 30],
            ['media_asset_id' => $imageC->id, 'slot' => 'trailer_poster', 'sort_order' => 40],
            ['media_asset_id' => $imageB->id, 'slot' => 'soundtrack_cover', 'sort_order' => 50],
        ]);
        Track::create([
            'id' => 'qa-media-audio',
            'title' => 'QA audio',
            'cover_asset_id' => $imageA->id,
            'audio_asset_id' => $audio->id,
            'duration' => 1.0,
            'peaks' => null,
            'genres' => [],
            'emotions' => [],
            'platform_overrides' => [],
            'status' => 'published',
            'rights_scope' => 'existing_local_draft',
            'library_visible' => true,
        ]);

        $package = app(ManifestExporter::class)->package(ContentValidator::POLICY_DRAFT_PREVIEW);
        $projected = collect($package['manifest']['projects'])->firstWhere('id', $project->id);
        $this->assertSame([$imageB->id, $imageA->id], $projected['gallery']);
        $this->assertSame($imageA->id, ((array) $projected['memberships'])['music']['coverAssetId']);
        $this->assertSame($video->id, ((array) $projected['memberships'])['music']['hoverAssetId']);
        $this->assertSame($video->id, $projected['localTrailerAssetId']);
        $this->assertSame($imageC->id, $projected['localTrailerPosterId']);
        $this->assertSame($audio->id, collect($package['manifest']['tracks'])->firstWhere('id', 'qa-media-audio')['audioAssetId']);
        $this->assertSame($imageA->id, collect($package['manifest']['tracks'])->firstWhere('id', 'qa-media-audio')['coverAssetId']);
        $this->assertTrue(collect($package['manifest']['assets'])->contains(fn($asset) => $asset['id'] === $imageA->id));
        $additional = ((array) app(SettingsExporter::class)->export()['projectMedia'])[$project->id];
        $this->assertTrue(collect($additional)->contains(
            fn($row) => $row['slot'] === 'soundtrack_cover' && $row['media_asset_id'] === $imageB->id,
        ));

        Setting::put('seo', [...Setting::read('seo'), 'ogAssetId' => $imageC->id]);
        $this->assertSame($imageC->id, app(SettingsExporter::class)->export()['seo']['ogAssetId']);
        $this->assertArrayHasKey($imageA->id, Fields::mediaOptions('image'));
        $this->assertArrayNotHasKey($video->id, Fields::mediaOptions('image'));
        MediaAsset::create([
            'id' => 'qa-bad-mime',
            'path' => 'media/qa-bad-mime.jpg',
            'type' => 'image',
            'mime_type' => 'video/mp4',
            'purpose' => 'other',
            'alt' => 'Bad MIME',
            'state' => 'test',
            'rights_scope' => 'unconfirmed',
            'crop' => ['x' => 50, 'y' => 50],
        ]);
        $this->assertArrayNotHasKey('qa-bad-mime', Fields::mediaOptions('image'));

        try {
            $project->mediaAssignments()->create(['media_asset_id' => $video->id, 'slot' => 'gallery', 'sort_order' => 60]);
            $this->fail('Gallery must reject video.');
        } catch (DomainException) {
        }
        try {
            Track::findOrFail('qa-media-audio')->update(['audio_asset_id' => $imageA->id]);
            $this->fail('Playback must reject image.');
        } catch (DomainException) {
        }
        try {
            Track::findOrFail('qa-media-audio')->update(['cover_asset_id' => $video->id]);
            $this->fail('Track cover must reject video.');
        } catch (DomainException) {
        }
        try {
            $project->update(['seo' => ['ogAssetId' => $video->id]]);
            $this->fail('SEO must reject video.');
        } catch (DomainException) {
        }

        $usages = app(MediaUsageResolver::class)->usages($imageB);
        $this->assertTrue(collect($usages)->contains(
            fn($usage) => $usage['location'] === 'About' && $usage['slot'] === 'Poster' && ! $usage['active'],
        ));
        $this->expectException(DomainException::class);
        app(MediaUsageResolver::class)->deleteUnused($imageB);
    }
}
