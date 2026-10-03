<?php

namespace Tests\Feature;

use App\Services\ContentValidator;
use App\Services\ManifestExporter;
use App\Services\ManifestImporter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionReleaseManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $manifest = json_decode(file_get_contents(config('cms.manifest')), true, flags: JSON_THROW_ON_ERROR);
        app(ManifestImporter::class)->import($manifest);
    }

    public function test_prototype_package_stays_default_and_release_package_is_explicit_and_strict(): void
    {
        $this->assertSame('prototype', app(ManifestExporter::class)->package()['manifest']['mode']);

        $spy = new class extends ContentValidator
        {
            public array $calls = [];

            public function validate(array $m, bool $release = false, bool $checkFiles = true, string $policy = self::POLICY_PUBLICATION): void
            {
                $this->calls[] = compact('release', 'checkFiles', 'policy');
            }
        };
        app()->instance(ContentValidator::class, $spy);
        $release = app(ManifestExporter::class)->releasePackage();

        $this->assertSame('release', $release['manifest']['mode']);
        $this->assertSame([], $release['manifest']['sources']);
        $this->assertSame([], array_unique(array_merge(...array_map(fn (array $project): array => $project['sourceIds'], $release['manifest']['projects']))));
        $this->assertFalse($spy->calls[0]['release']);
        $this->assertTrue($spy->calls[1]['release']);
        $this->assertTrue($spy->calls[1]['checkFiles']);
        $this->assertSame(ContentValidator::POLICY_PUBLICATION, $spy->calls[1]['policy']);
    }

    public function test_current_real_content_is_not_made_release_valid(): void
    {
        $this->expectException(DomainException::class);
        app(ManifestExporter::class)->releasePackage();
    }

    public function test_schema_driven_normalization_restores_every_nested_object_slot_without_changing_lists(): void
    {
        $manifest = [
            'sources' => [],
            'assets' => [['crop' => []]],
            'projects' => [[
                'credits' => [],
                'categories' => [],
                'memberships' => [
                    'music' => ['crop' => []],
                    'directing' => ['crop' => []],
                ],
                'awards' => [],
                'gallery' => [],
                'externalFilm' => [],
                'platformLinks' => [],
                'tracks' => [],
                'sourceIds' => [],
                'genres' => [],
                'emotions' => [],
                'externalLinks' => [],
            ]],
            'tracks' => [[
                'peaks' => [],
                'genres' => [],
                'emotions' => [],
                'platformOverrides' => [],
            ]],
            'site' => [
                'biography' => [],
                'cv' => [],
                'contacts' => [],
                'legal' => [],
                'artistLinks' => [],
                'profileLinks' => [],
                'socialLinks' => [],
                'collective' => [],
                'consent' => [],
                'analytics' => [],
                'mediaSlots' => [
                    'hero' => [],
                    'composerMenu' => [],
                    'directingMenu' => [],
                    'about' => [],
                ],
            ],
        ];

        $normalized = app(ContentValidator::class)->normalizeJsonObjectSlots($manifest);

        $this->assertIsObject($normalized['assets'][0]['crop']);
        $this->assertIsObject($normalized['projects'][0]['memberships']['music']['crop']);
        $this->assertIsObject($normalized['projects'][0]['memberships']['directing']['crop']);
        $this->assertIsObject($normalized['projects'][0]['externalFilm']);
        $this->assertIsObject($normalized['projects'][0]['platformLinks']);
        $this->assertIsObject($normalized['projects'][0]['externalLinks']);
        $this->assertIsObject($normalized['tracks'][0]['platformOverrides']);
        foreach (['contacts', 'legal', 'artistLinks', 'profileLinks', 'collective', 'consent', 'analytics'] as $key) {
            $this->assertIsObject($normalized['site'][$key]);
        }
        foreach (['hero', 'composerMenu', 'directingMenu', 'about'] as $key) {
            $this->assertIsObject($normalized['site']['mediaSlots'][$key]);
        }
        foreach (['sources', 'biography', 'cv'] as $key) {
            $this->assertIsArray($key === 'sources' ? $normalized[$key] : $normalized['site'][$key]);
        }
        foreach (['credits', 'categories', 'awards', 'gallery', 'tracks', 'sourceIds', 'genres', 'emotions'] as $key) {
            $this->assertIsArray($normalized['projects'][0][$key]);
        }
        foreach (['peaks', 'genres', 'emotions'] as $key) {
            $this->assertIsArray($normalized['tracks'][0][$key]);
        }
        $this->assertIsArray($normalized['site']['socialLinks']);
    }

    public function test_kinnhakenhank_release_policy_accepts_only_exact_v2_id_and_sha(): void
    {
        $validator = app(ContentValidator::class);
        $valid = $this->policyFixture();
        $validator->validate($valid, true, false);

        $wrongSha = $valid;
        $wrongSha['assets'][1]['sha256'] = str_repeat('a', 64);
        $this->assertValidationFails($wrongSha, 'Prüfsumme');

        $wrongId = $valid;
        $wrongId['assets'][1]['id'] = 'local-f-unapproved-trailer';
        $wrongId['projects'][0]['localTrailerAssetId'] = 'local-f-unapproved-trailer';
        $this->assertValidationFails($wrongId, 'V2');
    }

    public function test_kinnhakenhank_external_and_alternate_slot_videos_fail_release(): void
    {
        $external = $this->policyFixture();
        $external['projects'][0]['externalFilm'] = [
            'provider' => 'youtube',
            'videoId' => 'full-film',
            'url' => 'https://example.test/full-film',
        ];
        $this->assertValidationFails($external, 'Kinnhakenhank');

        $hover = $this->policyFixture();
        $hover['assets'][] = [
            'id' => 'local-f-1967744a58884a30',
            'type' => 'video',
            'path' => 'media/kinnhakenhank-silent-preview.mp4',
            'duration' => 18.28,
            'alt' => 'Unapproved silent preview',
            'crop' => ['x' => 50, 'y' => 50],
            'origin' => 'Policy fixture',
            'source' => 'Policy fixture',
            'state' => 'approved',
            'sha256' => str_repeat('2', 64),
        ];
        $hover['projects'][0]['memberships']['music'] = [
            'order' => 1,
            'role' => 'Composer',
            'coverAssetId' => 'release-cover',
            'crop' => ['x' => 50, 'y' => 50],
            'hoverAssetId' => 'local-f-1967744a58884a30',
        ];
        $this->assertValidationFails($hover, 'nicht freigegebene Video-Referenz');
    }

    public function test_letterboxd_no_video_release_regression_passes(): void
    {
        $manifest = $this->policyFixture();
        $manifest['projects'][0]['id'] = 'letterboxd-everything-everywhere-all-at-once';
        $manifest['projects'][0]['slug'] = 'letterboxd-everything-everywhere-all-at-once';
        unset($manifest['projects'][0]['localTrailerAssetId']);

        app(ContentValidator::class)->validate($manifest, true, false);
        $this->addToAssertionCount(1);
    }

    private function policyFixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/kinnhakenhank-release-manifest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function assertValidationFails(array $manifest, string $message): void
    {
        try {
            app(ContentValidator::class)->validate($manifest, true, false);
            $this->fail('Release validation was expected to fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
