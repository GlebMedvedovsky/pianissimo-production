<?php

namespace Tests\Feature;

use App\Enums\ProductionPublicationStatus;
use App\Models\AuditEvent;
use App\Models\ProductionPublication;
use App\Services\ProductionPublicationPackager;
use App\Services\ProductionPublicationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductionPublicationTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $mediaRoot;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $crossRuntimeRoot = getenv('PUBLICATION_CROSS_RUNTIME_ROOT');
        $this->root = is_string($crossRuntimeRoot) && $crossRuntimeRoot !== ''
            ? $crossRuntimeRoot
            : storage_path('framework/testing/production-publication-'.bin2hex(random_bytes(5)));
        $this->mediaRoot = $this->root.DIRECTORY_SEPARATOR.'allowed-media';
        $this->source = $this->mediaRoot.DIRECTORY_SEPARATOR.'release-image.jpg';
        File::ensureDirectoryExists($this->mediaRoot);
        file_put_contents($this->source, 'deterministic-public-release-image');

        config([
            'cms.publication_package_root' => $this->root.DIRECTORY_SEPARATOR.'packages',
            'cms.media_root' => $this->mediaRoot,
            'cms.managed_media_root' => $this->mediaRoot.DIRECTORY_SEPARATOR.'cms',
            'cms.public_site_root' => $this->root.DIRECTORY_SEPARATOR.'live',
            'cms.static_release_root' => $this->root.DIRECTORY_SEPARATOR.'releases/static',
        ]);
    }

    protected function tearDown(): void
    {
        if (getenv('PUBLICATION_CROSS_RUNTIME_ROOT') === false) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_uuid_format_uniqueness_and_monotonic_sequence_allocation(): void
    {
        $first = $this->packager()->prepare($this->payload('one'));
        $second = $this->packager()->prepare($this->payload('two'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->publication_id);
        $this->assertNotSame($first->publication_id, $second->publication_id);
        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
    }

    public function test_same_logical_publication_is_idempotent_and_digest_is_deterministic(): void
    {
        $payload = $this->payload('same');
        $first = $this->packager()->prepare($payload);
        $payload['manifest'] = array_reverse($payload['manifest'], true);
        $second = $this->packager()->prepare($payload);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->publication_id, $second->publication_id);
        $this->assertSame($first->package_sha256, $second->package_sha256);
        $this->assertSame(1, ProductionPublication::count());
    }

    public function test_canonical_manifest_preserves_recursive_json_object_and_array_types(): void
    {
        $payload = $this->payload('json-types');
        $audioSource = $this->mediaRoot.DIRECTORY_SEPARATOR.'release-audio.mp3';
        file_put_contents($audioSource, 'deterministic-public-release-audio');
        $audioSha = hash_file('sha256', $audioSource);
        $payload['manifest']['assets'][] = [
            'id' => 'release-audio',
            'type' => 'audio',
            'path' => 'media/release-audio.mp3',
            'duration' => 1,
            'alt' => 'Release audio fixture',
            'crop' => ['x' => 50, 'y' => 50],
            'origin' => 'Dedicated production package test fixture',
            'source' => 'Generated test bytes',
            'state' => 'approved',
            'sha256' => $audioSha,
        ];
        $payload['media'][] = [
            'assetId' => 'release-audio',
            'type' => 'audio',
            'publicPath' => 'media/release-audio.mp3',
            'sourcePath' => $audioSource,
            'sha256' => $audioSha,
            'size' => filesize($audioSource),
        ];
        $payload['manifest']['projects'] = [[
            'id' => 'json-types',
            'slug' => 'json-types',
            'title' => 'JSON types',
            'synopsis' => 'Canonical object and array regression fixture',
            'status' => 'published',
            'order' => 1,
            'productionType' => 'Test fixture',
            'year' => null,
            'credits' => [],
            'categories' => [],
            'memberships' => (object) [],
            'awards' => [],
            'gallery' => [],
            'externalFilm' => null,
            'platformLinks' => (object) [
                'tidal' => 'https://tidal.com/album/json-types',
                'spotify' => 'https://open.spotify.com/album/json-types',
            ],
            'tracks' => [],
            'sourceIds' => [],
            'externalLinks' => (object) [],
        ]];
        $payload['manifest']['tracks'] = [[
            'id' => 'json-types-track',
            'title' => 'JSON types track',
            'audioAssetId' => 'release-audio',
            'duration' => 1,
            'peaks' => [],
            'genres' => [],
            'emotions' => [],
            'platformOverrides' => (object) [],
            'status' => 'published',
            'libraryVisible' => true,
        ]];
        $payload['manifest']['site']['artistLinks'] = (object) [];
        $payload['manifest']['site']['profileLinks'] = (object) [];
        $payload['manifest']['assets'][0]['width'] = 1.0;
        $payload['manifest']['assets'][0]['height'] = 6.63e-5;

        $releaseManifest = $payload['manifest'];
        $payload['manifest'] = json_decode(
            json_encode($releaseManifest, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $first = $this->packager()->prepare($payload);
        $manifestPath = $this->packageDirectory($first).DIRECTORY_SEPARATOR.'manifest.json';
        $manifestBytes = file_get_contents($manifestPath);
        $this->assertIsString($manifestBytes);
        $this->assertStringContainsString('"memberships":{}', $manifestBytes);
        $this->assertStringContainsString('"externalLinks":{}', $manifestBytes);
        $this->assertStringContainsString('"platformOverrides":{}', $manifestBytes);
        $this->assertStringContainsString('"artistLinks":{}', $manifestBytes);
        $this->assertStringContainsString('"profileLinks":{}', $manifestBytes);
        $this->assertStringContainsString('"awards":[]', $manifestBytes);
        $this->assertStringContainsString('"peaks":[]', $manifestBytes);
        $this->assertStringContainsString('"width":1.0', $manifestBytes);
        $this->assertStringContainsString('"height":6.63e-5', $manifestBytes);
        $spotifyPosition = strpos($manifestBytes, '"spotify":"https://open.spotify.com/album/json-types"');
        $tidalPosition = strpos($manifestBytes, '"tidal":"https://tidal.com/album/json-types"');
        $this->assertNotFalse($spotifyPosition);
        $this->assertNotFalse($tidalPosition);
        $this->assertLessThan($tidalPosition, $spotifyPosition);

        $decoded = json_decode($manifestBytes, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded->projects);
        $this->assertIsObject($decoded->projects[0]->memberships);
        $this->assertIsObject($decoded->projects[0]->externalLinks);
        $this->assertIsArray($decoded->projects[0]->awards);
        $this->assertIsObject($decoded->tracks[0]->platformOverrides);
        $this->assertIsArray($decoded->tracks[0]->peaks);
        $this->assertIsObject($decoded->site->artistLinks);
        $this->assertIsObject($decoded->site->contacts);
        $this->assertIsObject($decoded->site->legal);
        $this->assertIsObject($decoded->site->consent);
        $this->assertIsObject($decoded->site->analytics);
        $this->assertIsObject($decoded->site->mediaSlots);
        $this->assertIsObject($decoded->site->mediaSlots->hero);

        $schemaInput = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        app(\App\Services\ContentValidator::class)->validate(
            $schemaInput,
            true,
            false,
            \App\Services\ContentValidator::POLICY_PUBLICATION,
        );

        $reordered = $payload;
        $reordered['manifest'] = $releaseManifest;
        $reordered['manifest'] = array_reverse($reordered['manifest'], true);
        $reordered['manifest']['projects'][0]['platformLinks'] = (object) [
            'spotify' => 'https://open.spotify.com/album/json-types',
            'tidal' => 'https://tidal.com/album/json-types',
        ];
        $second = $this->packager()->prepare($reordered);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->manifest_sha256, $second->manifest_sha256);
        $this->assertSame($first->package_sha256, $second->package_sha256);
        $this->assertSame($manifestBytes, file_get_contents($manifestPath));

    }

    public function test_conflicting_content_for_claimed_identity_is_rejected(): void
    {
        $payload = $this->payload('conflict');
        $this->packager()->prepare($payload);
        $other = $this->mediaRoot.DIRECTORY_SEPARATOR.'other.jpg';
        file_put_contents($other, 'different public bytes');
        $conflict = $payload;
        $sha = hash_file('sha256', $other);
        $conflict['manifest']['assets'][0]['sha256'] = $sha;
        $conflict['media'][0]['sourcePath'] = $other;
        $conflict['media'][0]['sha256'] = $sha;
        $conflict['media'][0]['size'] = filesize($other);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Conflicting content');
        $this->packager()->prepare($conflict);
    }

    public function test_legal_lifecycle_transitions_and_single_current_active_release(): void
    {
        $service = app(ProductionPublicationService::class);
        $first = $this->packager()->prepare($this->payload('active-one'));
        $first = $service->markDispatching($first);
        $first = $service->markDispatched($first);
        $first = $service->markBuilding($first);
        $first = $service->markBuilt($first, hash('sha256', 'release-one'), hash('sha256', 'release-files-one'), hash('sha256', 'release-metadata-one'));
        $first = $service->markUploading($first);
        $first = $service->markUploaded($first);
        $first = $service->markActivating($first);
        $first = $service->markActive($first);
        $this->assertSame(ProductionPublicationStatus::Active, $first->status);

        $second = $this->packager()->prepare($this->payload('active-two'));
        $second = $service->markDispatching($second);
        $second = $service->markDispatched($second);
        $second = $service->markBuilding($second);
        $second = $service->markBuilt($second, hash('sha256', 'release-two'), hash('sha256', 'release-files-two'), hash('sha256', 'release-metadata-two'));
        $second = $service->markUploading($second);
        $second = $service->markUploaded($second);
        $second = $service->markActivating($second);
        $second = $service->markActive($second);

        $this->assertSame(ProductionPublicationStatus::Superseded, $first->refresh()->status);
        $this->assertSame($second->getKey(), ProductionPublication::currentActive()?->getKey());
        $this->assertSame(1, ProductionPublication::where('status', ProductionPublicationStatus::Active->value)->count());
    }

    public function test_illegal_lifecycle_transition_is_rejected(): void
    {
        $publication = $this->packager()->prepare($this->payload('illegal'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('prepared -> active');
        $publication->status = ProductionPublicationStatus::Active;
        $publication->save();
    }

    public function test_existing_immutable_package_cannot_be_modified_or_silently_replaced(): void
    {
        $payload = $this->payload('immutable');
        $publication = $this->packager()->prepare($payload);
        file_put_contents($this->packageDirectory($publication).DIRECTORY_SEPARATOR.'media/release-image.jpg', 'tampered');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('changed');
        $this->packager()->prepare($payload);
    }

    public function test_traversal_and_absolute_public_paths_are_rejected(): void
    {
        foreach (['media/../secret.jpg', '/media/release-image.jpg', 'C:\\media\\release-image.jpg'] as $index => $unsafe) {
            $payload = $this->payload('unsafe-'.$index);
            $payload['manifest']['assets'][0]['path'] = $unsafe;
            $payload['media'][0]['publicPath'] = $unsafe;
            try {
                $this->packager()->prepare($payload);
                $this->fail("Unsafe path was accepted: {$unsafe}");
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_symlink_source_is_rejected(): void
    {
        $link = $this->mediaRoot.DIRECTORY_SEPARATOR.'linked.jpg';
        if (! @symlink($this->source, $link)) {
            $this->markTestSkipped('Symlink creation is unavailable on this test host.');
        }
        $payload = $this->payload('symlink');
        $payload['media'][0]['sourcePath'] = $link;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('non-symlink');
        $this->packager()->prepare($payload);
    }

    public function test_duplicate_normalized_public_path_is_rejected(): void
    {
        $payload = $this->payload('duplicate');
        $duplicate = $payload['media'][0];
        $duplicate['publicPath'] = 'media//release-image.jpg';
        $payload['media'][] = $duplicate;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Duplicate normalized');
        $this->packager()->prepare($payload);
    }

    public function test_missing_media_is_rejected(): void
    {
        $payload = $this->payload('missing');
        $payload['media'][0]['sourcePath'] = $this->mediaRoot.DIRECTORY_SEPARATOR.'missing.jpg';

        $this->expectException(DomainException::class);
        $this->packager()->prepare($payload);
    }

    public function test_directory_outside_root_and_unsupported_type_extension_are_rejected(): void
    {
        $directory = $this->payload('directory');
        $directory['media'][0]['sourcePath'] = $this->mediaRoot;
        try {
            $this->packager()->prepare($directory);
            $this->fail('A directory was accepted as production media.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('regular non-symlink file', $exception->getMessage());
        }

        $outsideFile = $this->root.DIRECTORY_SEPARATOR.'outside.jpg';
        file_put_contents($outsideFile, file_get_contents($this->source));
        $outside = $this->payload('outside');
        $outside['media'][0]['sourcePath'] = $outsideFile;
        try {
            $this->packager()->prepare($outside);
            $this->fail('Media outside configured roots was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('outside allowed media roots', $exception->getMessage());
        }

        $unsupported = $this->payload('unsupported');
        $unsupported['manifest']['assets'][0]['type'] = 'video';
        $unsupported['media'][0]['type'] = 'video';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unsupported production media extension/type');
        $this->packager()->prepare($unsupported);
    }

    public function test_checksum_and_size_mismatches_are_rejected(): void
    {
        $badChecksum = $this->payload('checksum');
        $badChecksum['manifest']['assets'][0]['sha256'] = str_repeat('a', 64);
        $badChecksum['media'][0]['sha256'] = str_repeat('a', 64);
        try {
            $this->packager()->prepare($badChecksum);
            $this->fail('Checksum mismatch was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('checksum mismatch', strtolower($exception->getMessage()));
        }

        $badSize = $this->payload('size');
        $badSize['media'][0]['size']++;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('size mismatch');
        $this->packager()->prepare($badSize);
    }

    public function test_non_release_manifest_is_rejected(): void
    {
        $payload = $this->payload('prototype');
        $payload['manifest']['mode'] = 'prototype';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('release-mode');
        $this->packager()->prepare($payload);
    }

    public function test_unreferenced_private_media_is_not_copied_and_metadata_contains_no_secrets(): void
    {
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'private-original.jpg', 'private original must remain private');
        $payload = $this->payload('public-only');
        $payload['dbPassword'] = 'DO-NOT-LEAK-PASSWORD';
        $payload['apiToken'] = 'DO-NOT-LEAK-TOKEN';
        $publication = $this->packager()->prepare($payload);
        $directory = $this->packageDirectory($publication);
        $metadata = file_get_contents($directory.DIRECTORY_SEPARATOR.'publication.json');

        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'media/private-original.jpg');
        $this->assertStringNotContainsString('DO-NOT-LEAK', $metadata);
        $this->assertStringNotContainsString($this->source, $metadata);
        $this->assertSame(['manifest.json', 'media', 'publication.json'], $this->directoryEntries($directory));
    }

    public function test_package_root_inside_public_or_live_static_paths_is_rejected(): void
    {
        $roots = [
            public_path('production-packages'),
            config('cms.public_site_root').DIRECTORY_SEPARATOR.'packages',
            config('cms.static_release_root').DIRECTORY_SEPARATOR.'current/packages',
        ];
        foreach ($roots as $index => $root) {
            config(['cms.publication_package_root' => $root]);
            try {
                $this->packager()->prepare($this->payload('public-root-'.$index));
                $this->fail("Public package root was accepted: {$root}");
            } catch (DomainException $exception) {
                $this->assertStringContainsString('outside live/static public paths', $exception->getMessage());
            }
        }
    }

    public function test_package_failure_leaves_no_final_package_and_only_safe_failure_audit(): void
    {
        $packageRoot = config('cms.publication_package_root');
        File::ensureDirectoryExists($packageRoot);
        file_put_contents($packageRoot.DIRECTORY_SEPARATOR.'production', 'blocks final production directory');
        $payload = $this->payload('failed');
        $payload['connectionString'] = 'mysql://admin:VERY-SECRET@example.test/database';

        try {
            $this->packager()->prepare($payload);
            $this->fail('Package publication failure was expected.');
        } catch (DomainException) {
            $publication = ProductionPublication::sole();
            $this->assertSame(ProductionPublicationStatus::Failed, $publication->status);
            $this->assertSame('package_preparation_failed', $publication->failure_code);
            $this->assertDirectoryDoesNotExist($packageRoot.DIRECTORY_SEPARATOR.$publication->package_path);
            $audit = AuditEvent::query()->get()->toJson();
            $this->assertStringNotContainsString('VERY-SECRET', $audit);
            $this->assertStringNotContainsString('mysql://', $audit);
        }
    }

    private function packager(): ProductionPublicationPackager
    {
        return app(ProductionPublicationPackager::class);
    }

    private function payload(string $identity): array
    {
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/valid-release-manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $sha = hash_file('sha256', $this->source);
        $manifest['assets'][0]['sha256'] = $sha;

        return [
            'manifest' => $manifest,
            'candidateHash' => hash('sha256', 'candidate-'.$identity),
            'planHash' => hash('sha256', 'plan-'.$identity),
            'sourceRevision' => sha1('revision-'.$identity),
            'media' => [[
                'assetId' => 'release-image',
                'type' => 'image',
                'publicPath' => 'media/release-image.jpg',
                'sourcePath' => $this->source,
                'sha256' => $sha,
                'size' => filesize($this->source),
            ]],
        ];
    }

    private function packageDirectory(ProductionPublication $publication): string
    {
        return config('cms.publication_package_root').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $publication->package_path);
    }

    private function directoryEntries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($entries);
        return $entries;
    }
}
