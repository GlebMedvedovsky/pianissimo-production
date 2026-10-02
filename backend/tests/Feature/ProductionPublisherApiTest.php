<?php

namespace Tests\Feature;

use App\Enums\ProductionPublicationStatus;
use App\Models\AuditEvent;
use App\Models\ProductionPublication;
use App\Services\ProductionPublicationPackager;
use App\Services\ProductionPublicationService;
use App\Services\ProductionPublicationTransport;
use App\Services\ProductionWorkflowDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class ProductionPublisherApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'publisher-test-token-0123456789abcdef';

    private string $root;
    private string $mediaRoot;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/production-publisher-api-'.bin2hex(random_bytes(5)));
        $this->mediaRoot = $this->root.DIRECTORY_SEPARATOR.'allowed-media';
        $this->source = $this->mediaRoot.DIRECTORY_SEPARATOR.'release-image.jpg';
        File::ensureDirectoryExists($this->mediaRoot);
        file_put_contents($this->source, 'publisher-api-release-image');
        config([
            'publisher.api_token' => self::TOKEN,
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

    public function test_bearer_authentication_fails_closed_and_never_exposes_token(): void
    {
        $publication = $this->publication('auth');
        $url = $this->claimUrl($publication);
        $missing = $this->postJson($url, ['runner_id' => 'runner-auth']);
        $wrong = $this->withToken(str_repeat('w', 32))->postJson($url, ['runner_id' => 'runner-auth']);
        config(['publisher.api_token' => 'short']);
        $misconfigured = $this->withToken('short')->postJson($url, ['runner_id' => 'runner-auth']);

        foreach ([$missing, $wrong, $misconfigured] as $response) {
            $response->assertUnauthorized()->assertJson(['message' => 'Publisher authentication failed.']);
            $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        }
        config(['publisher.api_token' => self::TOKEN]);
        $accepted = $this->authorized()->withHeader('Origin', 'https://untrusted.example')
            ->postJson($url, ['runner_id' => 'runner-auth']);
        $accepted->assertOk()->assertJsonPath('publication_id', $publication->publication_id);
        $this->assertNotSame('*', $accepted->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringNotContainsString(self::TOKEN, $accepted->getContent());
        $this->assertStringNotContainsString(self::TOKEN, AuditEvent::query()->get()->toJson());
    }

    public function test_claim_is_idempotent_for_owner_and_rejects_competing_runner(): void
    {
        $publication = $this->publication('claim');
        $url = $this->claimUrl($publication);
        $first = $this->authorized()->postJson($url, ['runner_id' => 'runner-one']);
        $retry = $this->authorized()->postJson($url, ['runner_id' => 'runner-one']);
        $competing = $this->authorized()->postJson($url, ['runner_id' => 'runner-two']);

        $first->assertOk()->assertJsonPath('claim_result', 'claimed')->assertJsonPath('status', 'dispatched');
        $retry->assertOk()->assertJsonPath('claim_result', 'already_claimed');
        $competing->assertStatus(409)->assertExactJson(['message' => 'Publication claim rejected.']);
        $this->assertSame('runner-one', $publication->refresh()->claimed_by);
    }

    public function test_unknown_and_traversing_publication_ids_are_rejected_generically(): void
    {
        $this->authorized()->postJson('/api/publisher/v1/publications/00000000-0000-4000-8000-000000000000/claim', ['runner_id' => 'runner-safe'])
            ->assertNotFound()
            ->assertJson(['message' => 'Publication unavailable.']);
        $traversal = $this->authorized()->postJson('/api/publisher/v1/publications/..%2F.env/claim', ['runner_id' => 'runner-safe']);
        $traversal->assertNotFound();
        $this->assertStringNotContainsString('APP_KEY', $traversal->getContent());
    }

    public function test_package_is_claim_bound_private_deterministic_and_contains_no_server_path(): void
    {
        $publication = $this->publication('package');
        $this->claim($publication, 'runner-package');
        $unauthorizedRunner = $this->authorized()->withHeader('X-Pianissimo-Runner-Id', 'runner-other')
            ->get($this->packageUrl($publication));
        $unauthorizedRunner->assertStatus(409);

        $transport = app(ProductionPublicationTransport::class);
        $first = $transport->create($publication->refresh());
        $second = $transport->create($publication->refresh());
        $this->assertSame($first['sha256'], $second['sha256']);
        $this->assertSame($first['sha256'], hash_file('sha256', $first['path']));
        $this->assertStringNotContainsString($this->root, $first['filename']);
        $this->assertSame(['manifest.json', 'media/release-image.jpg', 'publication.json'], $this->tarEntries($first['path']));
        @unlink($first['path']);
        @unlink($second['path']);

        $response = $this->authorized()->withHeader('X-Pianissimo-Runner-Id', 'runner-package')
            ->get($this->packageUrl($publication).'?path=../../.env');
        $response->assertOk()->assertHeader('X-Pianissimo-Package-Sha256', $publication->package_sha256);
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $headers = json_encode($response->headers->all());
        $this->assertStringNotContainsString($this->root, (string) $headers);
        @unlink($response->baseResponse->getFile()->getPathname());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_package_transport_respects_open_basedir_and_still_rejects_symlink_components_below_runtime_root(): void
    {
        $runtimeRoot = dirname(base_path(), 2);
        $openBasedir = $runtimeRoot.PATH_SEPARATOR.sys_get_temp_dir();
        $this->assertNotFalse(ini_set('open_basedir', $openBasedir));
        $this->assertSame($openBasedir, ini_get('open_basedir'));

        $publication = $this->publication('open-basedir');
        $transport = app(ProductionPublicationTransport::class);
        $first = $transport->create($publication);
        $second = $transport->create($publication);

        $this->assertSame($first['sha256'], $second['sha256']);
        $this->assertSame(['manifest.json', 'media/release-image.jpg', 'publication.json'], $this->tarEntries($first['path']));
        @unlink($first['path']);
        @unlink($second['path']);

        $symlinkTarget = $this->root.DIRECTORY_SEPARATOR.'symlink-target';
        $symlinkRoot = $this->root.DIRECTORY_SEPARATOR.'symlinked-packages';
        File::ensureDirectoryExists($symlinkTarget);
        if (! @symlink($symlinkTarget, $symlinkRoot)) {
            $this->markTestSkipped('Symlink creation is unavailable on this test host.');
        }
        config(['cms.publication_package_root' => $symlinkRoot]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unavailable');
        $transport->create($publication);
    }

    public function test_package_tampering_and_unrelated_files_block_download(): void
    {
        foreach (['tamper', 'unrelated'] as $case) {
            $publication = $this->publication($case);
            $runner = 'runner-'.$case;
            $this->claim($publication, $runner);
            $directory = $this->packageDirectory($publication);
            if ($case === 'tamper') {
                file_put_contents($directory.DIRECTORY_SEPARATOR.'media/release-image.jpg', 'tampered');
            } else {
                file_put_contents($directory.DIRECTORY_SEPARATOR.'.env', 'APP_KEY=secret');
            }
            $response = $this->authorized()->withHeader('X-Pianissimo-Runner-Id', $runner)
                ->get($this->packageUrl($publication));
            $response->assertStatus(409)->assertExactJson(['message' => 'Publication package unavailable.']);
            $this->assertStringNotContainsString($directory, $response->getContent());
        }
    }

    public function test_status_callbacks_require_owner_are_idempotent_and_cannot_forge_active(): void
    {
        $publication = $this->publication('status');
        $this->claim($publication, 'runner-status');
        $url = $this->statusUrl($publication);
        $this->authorized()->postJson($url, ['runner_id' => 'runner-other', 'status' => 'building'])->assertStatus(409);
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'active'])->assertUnprocessable();
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'building'])
            ->assertOk()->assertJsonPath('status', 'building');
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'building'])
            ->assertOk()->assertJsonPath('status', 'building');
        $releaseSha = hash('sha256', 'static-release');
        $releaseFilesSha = hash('sha256', 'static-release-files');
        $releaseMetadataSha = hash('sha256', 'static-release-metadata');
        $this->authorized()->postJson($url, [
            'runner_id' => 'runner-status', 'status' => 'built', 'release_sha256' => $releaseSha,
            'release_files_sha256' => $releaseFilesSha,
            'release_metadata_sha256' => $releaseMetadataSha,
        ])->assertOk()->assertJsonPath('status', 'built');
        $this->authorized()->postJson($url, [
            'runner_id' => 'runner-status', 'status' => 'built', 'release_sha256' => $releaseSha,
            'release_files_sha256' => $releaseFilesSha,
            'release_metadata_sha256' => hash('sha256', 'different-static-release-metadata'),
        ])->assertStatus(409);
        $this->authorized()->postJson($url, [
            'runner_id' => 'runner-status', 'status' => 'built', 'release_sha256' => $releaseSha,
            'release_files_sha256' => $releaseFilesSha,
            'release_metadata_sha256' => $releaseMetadataSha,
        ])->assertOk()->assertJsonPath('status', 'built');
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'building'])->assertStatus(409);
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'uploading'])
            ->assertOk()->assertJsonPath('status', 'uploading');
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'uploading'])
            ->assertOk()->assertJsonPath('status', 'uploading');
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'uploaded'])
            ->assertOk()->assertJsonPath('status', 'uploaded');
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'uploaded'])
            ->assertOk()->assertJsonPath('status', 'uploaded');
        $this->authorized()->postJson($url, ['runner_id' => 'runner-status', 'status' => 'active'])->assertUnprocessable();
        $this->assertSame(ProductionPublicationStatus::Uploaded, $publication->refresh()->status);
    }

    public function test_failed_callback_discards_runner_diagnostics_and_illegal_transition_is_rejected(): void
    {
        $publication = $this->publication('failure');
        $this->claim($publication, 'runner-failure');
        $url = $this->statusUrl($publication);
        $secret = 'Bearer SUPER-SECRET stack trace C:\\private\\app.php:99';
        $this->authorized()->postJson($url, [
            'runner_id' => 'runner-failure',
            'status' => 'failed',
            'error_code' => 'build_failed',
            'error_message' => $secret,
        ])->assertOk()->assertJsonPath('status', 'failed');
        $publication->refresh();
        $this->assertSame('build_failed', $publication->failure_code);
        $this->assertSame('Publisher reported a sanitized build failure. See the safe failure code.', $publication->failure_message);
        $this->assertStringNotContainsString('SUPER-SECRET', $publication->toJson());
        $retry = $this->authorized()->postJson($this->claimUrl($publication), ['runner_id' => 'runner-failure']);
        $retry->assertOk()
            ->assertJsonPath('status', 'dispatched')
            ->assertJsonPath('resume.state', 'dispatched')
            ->assertJsonPath('resume.build_required', true);
    }

    public function test_rerun_resume_policy_covers_each_publisher_state_and_rejects_a_different_run(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $states = ['dispatched', 'building', 'built', 'uploading', 'uploaded', 'failed', 'activating', 'active'];
        foreach ($states as $state) {
            $publication = $this->publication('resume-'.$state);
            $runner = 'github-123456';
            $this->claim($publication, $runner);
            $service = app(ProductionPublicationService::class);
            if (in_array($state, ['building', 'built', 'uploading', 'uploaded', 'activating', 'active'], true)) {
                $publication = $service->markBuilding($publication->refresh());
            }
            if (in_array($state, ['built', 'uploading', 'uploaded', 'activating', 'active'], true)) {
                $publication = $service->markBuilt(
                    $publication,
                    hash('sha256', 'archive-'.$state),
                    hash('sha256', 'files-'.$state),
                    hash('sha256', 'metadata-'.$state),
                );
            }
            if (in_array($state, ['uploading', 'uploaded', 'activating', 'active'], true)) {
                $publication = $service->markUploading($publication);
            }
            if (in_array($state, ['uploaded', 'activating', 'active'], true)) {
                $publication = $service->markUploaded($publication);
            }
            if (in_array($state, ['activating', 'active'], true)) {
                $publication = $service->markActivating($publication);
            }
            if ($state === 'active') {
                $publication = $service->markActive($publication);
            }
            if ($state === 'failed') {
                $publication = $service->markFailed($publication->refresh(), 'workflow_failed');
            }

            $response = $this->authorized()->postJson($this->claimUrl($publication), ['runner_id' => $runner]);
            $response->assertOk()->assertJsonPath('claim_result', 'already_claimed');
            $expected = $state === 'failed' ? 'dispatched' : $state;
            $buildRequired = in_array($expected, ['dispatched', 'building', 'built', 'uploading'], true);
            $response->assertJsonPath('resume.state', $expected)
                ->assertJsonPath('resume.build_required', $buildRequired)
                ->assertJsonPath('resume.upload_required', $buildRequired)
                ->assertJsonPath('resume.activation_required', $expected !== 'active')
                ->assertJsonPath('resume.notify_building', $expected === 'dispatched')
                ->assertJsonPath('resume.notify_built', in_array($expected, ['dispatched', 'building'], true))
                ->assertJsonPath('resume.notify_uploading', in_array($expected, ['dispatched', 'building', 'built'], true));

            $package = $this->authorized()->withHeader('X-Pianissimo-Runner-Id', $runner)
                ->get($this->packageUrl($publication));
            if ($buildRequired) {
                $package->assertOk()->assertHeader('X-Pianissimo-Package-Sha256', $publication->package_sha256);
                @unlink($package->baseResponse->getFile()->getPathname());
            } else {
                $package->assertStatus(409)->assertExactJson(['message' => 'Publication package unavailable.']);
            }
            $this->authorized()->postJson($this->claimUrl($publication), ['runner_id' => 'github-999999'])
                ->assertStatus(409);
            $this->authorized()->withHeader('X-Pianissimo-Runner-Id', 'github-999999')
                ->get($this->packageUrl($publication))
                ->assertStatus(409);
        }

        $failedAfterBuild = $this->publication('resume-failed-after-build');
        $this->claim($failedAfterBuild, 'github-123456');
        $service = app(ProductionPublicationService::class);
        $failedAfterBuild = $service->markBuilding($failedAfterBuild->refresh());
        $archiveSha = hash('sha256', 'failed-built-archive');
        $filesSha = hash('sha256', 'failed-built-files');
        $metadataSha = hash('sha256', 'failed-built-metadata');
        $failedAfterBuild = $service->markBuilt($failedAfterBuild, $archiveSha, $filesSha, $metadataSha);
        $failedAfterBuild = $service->markFailed($failedAfterBuild, 'upload_failed');
        $this->authorized()->postJson($this->claimUrl($failedAfterBuild), ['runner_id' => 'github-123456'])
            ->assertOk()
            ->assertJsonPath('resume.state', 'built')
            ->assertJsonPath('release_sha256', $archiveSha)
            ->assertJsonPath('release_files_sha256', $filesSha)
            ->assertJsonPath('release_metadata_sha256', $metadataSha);
    }

    public function test_dispatch_payload_is_exact_and_route_has_auth_and_rate_limit_middleware(): void
    {
        $publication = $this->publication('dispatch');
        $payload = app(ProductionWorkflowDispatcher::class)->payload($publication);
        $this->assertSame([
            'publication_id' => $publication->publication_id,
            'sequence' => (string) $publication->sequence,
            'package_sha256' => $publication->package_sha256,
            'source_revision' => $publication->source_revision,
        ], $payload);
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/publisher/v1/publications/{publicationId}/claim');
        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('publisher.auth', $middleware);
        $this->assertContains('throttle:publisher', $middleware);
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

    private function authorized(): static
    {
        return $this->withToken(self::TOKEN)->withHeader('Accept', 'application/json');
    }

    private function claim(ProductionPublication $publication, string $runner): void
    {
        $this->authorized()->postJson($this->claimUrl($publication), ['runner_id' => $runner])->assertOk();
    }

    private function claimUrl(ProductionPublication $publication): string
    {
        return "/api/publisher/v1/publications/{$publication->publication_id}/claim";
    }

    private function packageUrl(ProductionPublication $publication): string
    {
        return "/api/publisher/v1/publications/{$publication->publication_id}/package";
    }

    private function statusUrl(ProductionPublication $publication): string
    {
        return "/api/publisher/v1/publications/{$publication->publication_id}/status";
    }

    private function packageDirectory(ProductionPublication $publication): string
    {
        return config('cms.publication_package_root').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $publication->package_path);
    }

    private function tarEntries(string $path): array
    {
        $bytes = file_get_contents($path);
        $this->assertIsString($bytes);
        $entries = [];
        $offset = 0;
        while (substr($bytes, $offset, 512) !== str_repeat("\0", 512)) {
            $header = substr($bytes, $offset, 512);
            $this->assertSame("ustar\0", substr($header, 257, 6));
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $entries[] = $prefix === '' ? $name : $prefix.'/'.$name;
            $size = octdec(trim(substr($header, 124, 12), "\0 "));
            $offset += 512 + $size + ((512 - ($size % 512)) % 512);
        }

        return $entries;
    }
}
