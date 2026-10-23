<?php

namespace Tests\Feature;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use App\Services\ProductionPublicationPackager;
use App\Services\ProductionPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductionContentSyncApiTest extends TestCase
{
    use RefreshDatabase;

    private const SYNC_TOKEN = 'content-sync-test-token-0123456789abcdef';
    private const PUBLISHER_TOKEN = 'publisher-test-token-0123456789abcdef';

    private string $root;
    private string $mediaRoot;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->root = storage_path('framework/testing/production-content-sync-'.bin2hex(random_bytes(5)));
        $this->mediaRoot = $this->root.DIRECTORY_SEPARATOR.'allowed-media';
        $this->source = $this->mediaRoot.DIRECTORY_SEPARATOR.'release-image.jpg';
        File::ensureDirectoryExists($this->mediaRoot);
        file_put_contents($this->source, 'content-sync-release-image');
        config([
            'publisher.api_token' => self::PUBLISHER_TOKEN,
            'publisher.content_sync_api_token' => self::SYNC_TOKEN,
            'cms.publication_package_root' => $this->root.DIRECTORY_SEPARATOR.'packages',
            'cms.media_root' => $this->mediaRoot,
            'cms.managed_media_root' => $this->mediaRoot.DIRECTORY_SEPARATOR.'cms',
            'cms.public_site_root' => $this->root.DIRECTORY_SEPARATOR.'live',
            'cms.static_release_root' => $this->root.DIRECTORY_SEPARATOR.'releases/static',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_active_discovery_returns_only_the_current_immutable_identity(): void
    {
        $first = $this->activate($this->publication('first'), 'first');
        $second = $this->activate($this->publication('second'), 'second');

        $response = $this->sync()->getJson('/api/content-sync/v1/production/active');

        $response->assertOk()->assertExactJson([
            'contractVersion' => 1,
            'publicationId' => $second->publication_id,
            'sequence' => $second->sequence,
            'activatedAt' => $second->activated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'sourceRevision' => $second->source_revision,
            'packageSha256' => $second->package_sha256,
            'manifestSha256' => $second->manifest_sha256,
            'releaseSha256' => $second->release_sha256,
            'publicationUrl' => "/api/content-sync/v1/publications/{$second->publication_id}/publication.json",
            'manifestUrl' => "/api/content-sync/v1/publications/{$second->publication_id}/manifest.json",
            'mediaUrlTemplate' => "/api/content-sync/v1/publications/{$second->publication_id}/media/{sha256}",
        ]);
        $this->assertSame(ProductionPublicationStatus::Superseded, $first->refresh()->status);
        $this->assertStringNotContainsString($this->root, $response->getContent());
    }

    public function test_rollback_changes_discovery_and_retained_publications_remain_readable(): void
    {
        $first = $this->activate($this->publication('rollback-first'), 'rollback-first');
        $second = $this->activate($this->publication('rollback-second'), 'rollback-second');

        $this->sync()->get($this->publicationUrl($first))->assertOk();
        app(ProductionPublicationService::class)->activateRollback($first->refresh());

        $this->sync()->getJson('/api/content-sync/v1/production/active')
            ->assertOk()
            ->assertJsonPath('publicationId', $first->publication_id);
        $this->sync()->get($this->publicationUrl($second->refresh()))->assertOk();
    }

    public function test_exact_publication_manifest_and_verified_media_are_served_from_retained_package(): void
    {
        $publication = $this->activate($this->publication('exact'), 'exact');
        $directory = $this->packageDirectory($publication);
        $publicationBytes = file_get_contents($directory.DIRECTORY_SEPARATOR.'publication.json');
        $manifestBytes = file_get_contents($directory.DIRECTORY_SEPARATOR.'manifest.json');
        $sha256 = hash_file('sha256', $directory.DIRECTORY_SEPARATOR.'media/release-image.jpg');

        $this->sync()->get($this->publicationUrl($publication))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertContent($publicationBytes);
        $this->sync()->get($this->manifestUrl($publication))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertContent($manifestBytes);

        $media = $this->sync()->get($this->mediaUrl($publication, $sha256));
        $media->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Length', (string) strlen('content-sync-release-image'))
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertHeader('Accept-Ranges', 'bytes');
        $headers = json_encode($media->headers->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->root, $headers);
        $this->assertStringNotContainsString(self::SYNC_TOKEN, $headers);
    }

    public function test_media_supports_safe_byte_ranges(): void
    {
        $publication = $this->activate($this->publication('range'), 'range');
        $sha256 = hash_file('sha256', $this->packageDirectory($publication).DIRECTORY_SEPARATOR.'media/release-image.jpg');

        $this->sync()->withHeader('Range', 'bytes=0-3')->get($this->mediaUrl($publication, $sha256))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-3/'.strlen('content-sync-release-image'))
            ->assertHeader('Content-Length', '4');
    }

    public function test_tampered_publication_metadata_fails_closed_without_exposing_paths(): void
    {
        $publication = $this->activate($this->publication('metadata-tamper'), 'metadata-tamper');
        $path = $this->packageDirectory($publication).DIRECTORY_SEPARATOR.'publication.json';
        $metadata = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metadata['createdAt'] = '2000-01-01T00:00:00Z';
        ksort($metadata, SORT_STRING);
        file_put_contents($path, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");

        $response = $this->sync()->get($this->publicationUrl($publication));
        $response->assertStatus(409)->assertExactJson(['message' => 'Production publication package unavailable.']);
        $this->assertStringNotContainsString($this->root, $response->getContent());
    }

    public function test_tampered_manifest_and_media_fail_closed(): void
    {
        $manifestPublication = $this->activate($this->publication('manifest-tamper'), 'manifest-tamper');
        file_put_contents($this->packageDirectory($manifestPublication).DIRECTORY_SEPARATOR.'manifest.json', '{}');
        $this->sync()->get($this->manifestUrl($manifestPublication))->assertStatus(409);

        $mediaPublication = $this->activate($this->publication('media-tamper'), 'media-tamper');
        $mediaPath = $this->packageDirectory($mediaPublication).DIRECTORY_SEPARATOR.'media/release-image.jpg';
        $sha256 = hash_file('sha256', $mediaPath);
        file_put_contents($mediaPath, 'tampered-media');
        $this->sync()->get($this->mediaUrl($mediaPublication, $sha256))
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Production publication media unavailable.']);
    }

    public function test_unsafe_inventory_path_and_symlink_are_rejected(): void
    {
        $traversalPublication = $this->activate($this->publication('traversal'), 'traversal');
        $metadataPath = $this->packageDirectory($traversalPublication).DIRECTORY_SEPARATOR.'publication.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['media'][0]['publicPath'] = 'media/../release-image.jpg';
        file_put_contents($metadataPath, $this->canonicalJson($metadata)."\n");
        $this->sync()->get($this->publicationUrl($traversalPublication))->assertStatus(409);

        $symlinkPublication = $this->activate($this->publication('symlink'), 'symlink');
        $mediaPath = $this->packageDirectory($symlinkPublication).DIRECTORY_SEPARATOR.'media/release-image.jpg';
        @unlink($mediaPath);
        if (! @symlink($this->source, $mediaPath)) {
            $this->markTestSkipped('Symlink creation is unavailable on this test host.');
        }
        $this->sync()->get($this->publicationUrl($symlinkPublication))->assertStatus(409);
    }

    public function test_never_activated_failed_and_unknown_publications_are_inaccessible(): void
    {
        $prepared = $this->publication('prepared');
        $failed = app(ProductionPublicationService::class)->markFailed($this->publication('failed'), 'build_failed');

        foreach ([$prepared, $failed] as $publication) {
            $this->sync()->get($this->publicationUrl($publication))
                ->assertNotFound()
                ->assertExactJson(['message' => 'Production publication unavailable.']);
        }
        $this->sync()->get('/api/content-sync/v1/publications/00000000-0000-4000-8000-000000000000/publication.json')
            ->assertNotFound();
    }

    public function test_tokens_are_separate_unless_explicitly_configured_the_same(): void
    {
        $publication = $this->publication('token-separation');
        $this->withToken(self::SYNC_TOKEN)->postJson(
            "/api/publisher/v1/publications/{$publication->publication_id}/claim",
            ['runner_id' => 'sync-token-runner'],
        )->assertUnauthorized();
        $this->withToken(self::PUBLISHER_TOKEN)->getJson('/api/content-sync/v1/production/active')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Content sync authentication failed.']);

        config(['publisher.content_sync_api_token' => self::PUBLISHER_TOKEN]);
        $this->withToken(self::PUBLISHER_TOKEN)->getJson('/api/content-sync/v1/production/active')
            ->assertNotFound();
    }

    public function test_content_sync_authentication_fails_generically_without_exposing_tokens(): void
    {
        $missing = $this->getJson('/api/content-sync/v1/production/active');
        $wrong = $this->withToken(str_repeat('w', 32))->getJson('/api/content-sync/v1/production/active');
        config(['publisher.content_sync_api_token' => 'short']);
        $misconfigured = $this->withToken('short')->getJson('/api/content-sync/v1/production/active');

        foreach ([$missing, $wrong, $misconfigured] as $response) {
            $response->assertUnauthorized()->assertExactJson(['message' => 'Content sync authentication failed.']);
            $this->assertStringNotContainsString(self::SYNC_TOKEN, $response->getContent());
            $this->assertStringNotContainsString(self::PUBLISHER_TOKEN, $response->getContent());
        }
    }

    public function test_content_sync_routes_use_dedicated_auth_and_throttle(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/content-sync/v1/production/active');
        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('content-sync.auth', $middleware);
        $this->assertContains('throttle:content-sync', $middleware);
        $this->assertNotContains('publisher.auth', $middleware);
    }

    public function test_production_content_sync_rejects_insecure_transport_before_authentication(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->withToken(self::SYNC_TOKEN)->getJson('http://localhost/api/content-sync/v1/production/active')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Content sync transport rejected.']);
    }

    private function publication(string $identity): ProductionPublication
    {
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/valid-release-manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $sha = hash_file('sha256', $this->source);
        $manifest['assets'][0]['sha256'] = $sha;

        return app(ProductionPublicationPackager::class)->prepare([
            'manifest' => $manifest,
            'candidateHash' => hash('sha256', 'candidate-'.$identity),
            'planHash' => hash('sha256', 'plan-'.$identity),
            'sourceRevision' => sha1('revision-'.$identity),
            'media' => [[
                'assetId' => 'release-image', 'type' => 'image', 'publicPath' => 'media/release-image.jpg',
                'sourcePath' => $this->source, 'sha256' => $sha, 'size' => filesize($this->source),
            ]],
        ]);
    }

    private function activate(ProductionPublication $publication, string $identity): ProductionPublication
    {
        $service = app(ProductionPublicationService::class);
        $runner = 'content-sync-'.$identity;
        $claimed = $service->claimForRunner($publication, $runner)['publication'];
        $publication = $service->resumeClaimedWorkflow($claimed, $runner)['publication'];
        $publication = $service->markBuilding($publication);
        $publication = $service->markBuilt(
            $publication,
            hash('sha256', 'release-'.$identity),
            hash('sha256', 'files-'.$identity),
            hash('sha256', 'metadata-'.$identity),
        );
        $publication = $service->markUploading($publication);
        $publication = $service->markUploaded($publication);
        $publication = $service->markActivating($publication);

        return $service->markActive($publication);
    }

    private function sync(): static
    {
        return $this->withToken(self::SYNC_TOKEN)->withHeader('Accept', 'application/json');
    }

    private function publicationUrl(ProductionPublication $publication): string
    {
        return "/api/content-sync/v1/publications/{$publication->publication_id}/publication.json";
    }

    private function manifestUrl(ProductionPublication $publication): string
    {
        return "/api/content-sync/v1/publications/{$publication->publication_id}/manifest.json";
    }

    private function mediaUrl(ProductionPublication $publication, string $sha256): string
    {
        return "/api/content-sync/v1/publications/{$publication->publication_id}/media/{$sha256}";
    }

    private function packageDirectory(ProductionPublication $publication): string
    {
        return config('cms.publication_package_root').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $publication->package_path);
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn (mixed $item): mixed => json_decode($this->canonicalJson($item), true), $value);
            } else {
                ksort($value, SORT_STRING);
                foreach ($value as $key => $item) $value[$key] = json_decode($this->canonicalJson($item), true);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
