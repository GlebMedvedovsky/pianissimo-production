<?php

namespace Tests\Feature;

use App\Enums\ProductionPublicationStatus;
use App\Models\MediaAsset;
use App\Models\Membership;
use App\Models\ProductionPublication;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Track;
use App\Models\User;
use App\Services\FrontendPublication;
use App\Services\ContentValidator;
use App\Services\ManifestExporter;
use App\Services\ManifestImporter;
use App\Services\ProductionPublicationCoordinator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionPublicationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_ASSET_ID = 'legacy-unreferenced-temporary';

    private string $root;
    private string $exportRoot;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $manifest = json_decode(file_get_contents(config('cms.manifest')), true, flags: JSON_THROW_ON_ERROR);
        $privateSources = $manifest['sources'];
        $privateSourceIds = array_column($manifest['projects'], 'sourceIds', 'id');
        app(ManifestImporter::class)->import($manifest);
        MediaAsset::query()->update(['state' => 'approved']);
        foreach (MediaAsset::query()->whereNull('sha256')->get() as $asset) {
            $managed = str_starts_with($asset->path, 'media/cms/');
            $file = ($managed ? config('cms.managed_media_root') : config('cms.media_root'))
                .'/'.substr($asset->path, $managed ? 10 : 6);
            if (! is_file($file)) throw new \RuntimeException('Release test fixture media is missing: '.$asset->id);
            $asset->update(['sha256' => hash_file('sha256', $file)]);
        }
        Membership::query()->where('project_id', 'kinnhakenhank')->update(['hover_asset_id' => null]);
        Track::query()->whereNull('audio_asset_id')->update(['status' => 'draft']);
        foreach (['impressum', 'privacy'] as $key) {
            Setting::put($key, [...Setting::read($key), 'status' => 'published']);
        }
        $manifest = app(ManifestExporter::class)->package()['manifest'];
        $manifest['sources'] = $privateSources;
        foreach ($manifest['projects'] as &$project) {
            $project['sourceIds'] = $privateSourceIds[$project['id']] ?? [];
        }
        unset($project);
        $legacyAsset = $manifest['assets'][0];
        $legacyAsset['id'] = self::LEGACY_ASSET_ID;
        $legacyAsset['state'] = 'temporary';
        $legacyAsset['alt'] = 'Unreferenced legacy test fixture';
        $manifest['assets'][] = $legacyAsset;
        $this->root = storage_path('framework/testing/production-coordinator-'.bin2hex(random_bytes(5)));
        $this->exportRoot = storage_path('app/private/production-coordinator-tests/'.basename($this->root));
        File::ensureDirectoryExists($this->root);
        file_put_contents(
            $this->root.'/manifest.json',
            json_encode(app(ContentValidator::class)->normalizeJsonObjectSlots($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        config([
            'cms.manifest' => $this->root.'/manifest.json',
            'cms.active_manifest' => $this->root.'/manifest.json',
            'cms.frontend_apply_state' => $this->root.'/apply',
            'cms.publication_package_root' => $this->root.'/packages',
            'cms.public_site_root' => $this->root.'/httpdocs',
            'cms.static_release_root' => $this->root.'/static',
            'cms.export_prefix' => 'production-coordinator-tests/'.basename($this->root).'/exports',
            'cms.source_revision' => str_repeat('a', 40),
            'publisher.production_automation_enabled' => true,
            'publisher.github.repository' => 'owner/pianissimo',
            'publisher.github.ref' => 'main',
            'publisher.github.workflow' => 'pianissimo-production-publisher.yml',
            'publisher.github.token' => str_repeat('t', 40),
        ]);
        $this->admin = User::create(['name' => 'Production Owner', 'email' => 'production-owner@example.test', 'password' => bcrypt('test-password')]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->exportRoot);
        parent::tearDown();
    }

    public function test_accepted_state_creates_and_dispatches_one_exact_immutable_publication(): void
    {
        $accepted = $this->acceptChangedState();
        $acceptedManifest = json_decode((string) file_get_contents(config('cms.manifest')), true, flags: JSON_THROW_ON_ERROR);
        $fixtureMediaMismatches = [];
        foreach ($acceptedManifest['assets'] as $asset) {
            $managed = str_starts_with($asset['path'], 'media/cms/');
            $file = ($managed ? config('cms.managed_media_root') : config('cms.media_root'))
                .'/'.substr($asset['path'], $managed ? 10 : 6);
            if (! isset($asset['sha256']) || ! is_file($file) || ! hash_equals($asset['sha256'], hash_file('sha256', $file))) {
                $fixtureMediaMismatches[] = $asset['id'].(isset($asset['sha256']) ? '' : ' (missing SHA-256)');
            }
        }
        $this->assertSame([], $fixtureMediaMismatches, 'The release-ready fixture must match its public media bytes.');
        $this->assertNotEmpty(array_filter(
            $acceptedManifest['projects'],
            fn (array $project): bool => $project['sourceIds'] !== [],
        ), 'The accepted CMS manifest must retain its private source references before release normalization.');
        $acceptedLegacyAsset = collect($acceptedManifest['assets'])->firstWhere('id', self::LEGACY_ASSET_ID);
        $this->assertIsArray($acceptedLegacyAsset);
        $this->assertSame('temporary', $acceptedLegacyAsset['state']);
        Http::fake(['*' => Http::response(null, 204)]);

        $publication = app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
        $packageRoot = config('cms.publication_package_root').'/'.$publication->package_path;
        $releaseManifest = json_decode((string) file_get_contents($packageRoot.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $publicationMetadata = json_decode((string) file_get_contents($packageRoot.'/publication.json'), true, flags: JSON_THROW_ON_ERROR);
        $expectedAssetIds = $this->referencedAssetIds($acceptedManifest);
        $releaseAssetIds = array_column($releaseManifest['assets'], 'id');
        $inventoryAssetIds = array_column($publicationMetadata['media'], 'assetId');
        sort($releaseAssetIds);
        sort($inventoryAssetIds);

        $this->assertSame(ProductionPublicationStatus::Dispatching, $publication->status);
        $this->assertSame($accepted['candidateHash'], $publication->candidate_hash);
        $this->assertSame($accepted['planHash'], $publication->plan_hash);
        $this->assertSame(str_repeat('a', 40), $publication->source_revision);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $publication->package_sha256);
        $this->assertSame([], $releaseManifest['sources']);
        $this->assertSame(
            [],
            array_values(array_filter(array_column($releaseManifest['projects'], 'sourceIds'))),
            'Every release project must omit private source references.',
        );
        $this->assertNotContains(self::LEGACY_ASSET_ID, $releaseAssetIds);
        $this->assertSame($expectedAssetIds, $releaseAssetIds);
        $this->assertSame($expectedAssetIds, $inventoryAssetIds);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://api.github.com/repos/owner/pianissimo/actions/workflows/pianissimo-production-publisher.yml/dispatches'
            && $request['ref'] === 'main'
            && $request['inputs'] === [
                'publication_id' => $publication->publication_id,
                'sequence' => (string) $publication->sequence,
                'package_sha256' => $publication->package_sha256,
                'source_revision' => $publication->source_revision,
            ]
        );

        $duplicate = app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
        $this->assertSame($publication->getKey(), $duplicate->getKey());
        $this->assertSame(1, ProductionPublication::count());
        Http::assertSentCount(1);
    }

    public function test_referenced_temporary_media_still_blocks_production(): void
    {
        $manifest = $this->readActiveManifest();
        $assetId = $this->referencedAssetIds($manifest)[0] ?? null;
        $this->assertIsString($assetId);
        MediaAsset::query()->findOrFail($assetId)->update(['state' => 'temporary']);
        foreach ($manifest['assets'] as &$asset) {
            if ($asset['id'] === $assetId) $asset['state'] = 'temporary';
        }
        unset($asset);
        $this->writeActiveManifest($manifest);
        $this->acceptChangedState();
        Http::fake();

        try {
            app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
            $this->fail('Referenced temporary media must block production publication.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('temporäre Medien', $exception->getMessage());
        }

        $this->assertSame(0, ProductionPublication::count());
        Http::assertNothingSent();
    }

    public function test_accepted_manifest_hash_is_verified_before_legacy_media_projection(): void
    {
        $accepted = $this->acceptChangedState();
        $originalHash = hash_file('sha256', config('cms.manifest'));
        $plan = json_decode((string) file_get_contents(
            config('cms.frontend_apply_state').'/plans/'.$accepted['planHash'].'.json'
        ), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($originalHash, $plan['resultingManifestHash']);

        $manifest = $this->readActiveManifest();
        foreach ($manifest['assets'] as &$asset) {
            if ($asset['id'] === self::LEGACY_ASSET_ID) $asset['alt'] = 'Tampered after acceptance';
        }
        unset($asset);
        $this->writeActiveManifest($manifest);
        $this->assertNotSame($originalHash, hash_file('sha256', config('cms.manifest')));
        Http::fake();

        try {
            app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
            $this->fail('Tampering with unreferenced legacy media must invalidate the accepted manifest receipt.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('aktive Website-Stand stimmt nicht', $exception->getMessage());
        }

        $this->assertSame(0, ProductionPublication::count());
        Http::assertNothingSent();
    }

    public function test_unaccepted_state_is_rejected_before_publication_or_dispatch(): void
    {
        Http::fake();
        $this->expectException(DomainException::class);
        try {
            app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
        } finally {
            $this->assertSame(0, ProductionPublication::count());
            Http::assertNothingSent();
        }
    }

    public function test_default_off_gate_rejects_direct_action_without_consuming_a_sequence(): void
    {
        $this->acceptChangedState();
        config(['publisher.production_automation_enabled' => false]);
        Http::fake();
        try {
            app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
            $this->fail('Disabled production automation must reject direct invocation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('nicht freigeschaltet', $exception->getMessage());
        }
        $this->assertSame(0, ProductionPublication::count());
        Http::assertNothingSent();
    }

    public function test_dispatch_failure_is_historical_and_retry_gets_a_new_sequence(): void
    {
        $this->acceptChangedState();
        Http::fakeSequence()->pushStatus(500)->pushStatus(204);
        $failedPublicationId = null;
        try {
            app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
            $this->fail('Dispatch failure was expected.');
        } catch (DomainException) {
            $failed = ProductionPublication::sole();
            $failedPublicationId = $failed->getKey();
            $this->assertSame(ProductionPublicationStatus::Failed, $failed->status);
            $this->assertSame('workflow_dispatch_failed', $failed->failure_code);
            $this->assertSame(1, $failed->sequence);
        }

        $retry = app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
        $this->assertSame(2, $retry->sequence);
        $this->assertSame(2, $retry->attempt);
        $this->assertSame(ProductionPublicationStatus::Failed, ProductionPublication::findOrFail($failedPublicationId)->status);
        $this->assertSame(2, ProductionPublication::count());
    }

    public function test_existing_accepted_state_remains_publishable_after_a_new_draft_is_saved(): void
    {
        $accepted = $this->acceptChangedState();
        Project::query()->firstOrFail()->update(['synopsis' => 'A later, not accepted draft']);
        app(FrontendPublication::class)->currentReview();
        Http::fake(['*' => Http::response(null, 204)]);

        $publication = app(ProductionPublicationCoordinator::class)->publish($this->admin->id);

        $this->assertSame($accepted['candidateHash'], $publication->candidate_hash);
        $this->assertSame($accepted['planHash'], $publication->plan_hash);
    }

    public function test_accepted_cms_rollback_state_is_publishable(): void
    {
        $accepted = $this->acceptChangedState();
        $rollback = app(FrontendPublication::class)->rollbackReview($this->admin->id);
        $result = app(FrontendPublication::class)->rollback($rollback['planHash'], $this->admin->id);
        $this->assertSame('success', $result['status']);
        Http::fake(['*' => Http::response(null, 204)]);

        $publication = app(ProductionPublicationCoordinator::class)->publish($this->admin->id);

        $this->assertSame($accepted['candidateHash'], $publication->candidate_hash);
        $this->assertSame($rollback['planHash'], $publication->plan_hash);
        $this->assertSame(hash_file('sha256', config('cms.manifest')), $result['resultingManifestHash']);
        Http::assertSentCount(1);
    }

    public function test_unaccepted_draft_after_rollback_cannot_change_the_production_payload(): void
    {
        $originalManifest = json_decode((string) file_get_contents(config('cms.manifest')), true, flags: JSON_THROW_ON_ERROR);
        $project = Project::query()->where('status', 'published')->firstOrFail();
        $originalProject = collect($originalManifest['projects'])->firstWhere('id', $project->id);
        $this->assertIsArray($originalProject);
        $this->acceptChangedState();
        $rollback = app(FrontendPublication::class)->rollbackReview($this->admin->id);
        app(FrontendPublication::class)->rollback($rollback['planHash'], $this->admin->id);

        $unacceptedSynopsis = 'A later, not accepted draft after rollback';
        $project->update(['synopsis' => $unacceptedSynopsis]);
        app(FrontendPublication::class)->currentReview();
        Http::fake(['*' => Http::response(null, 204)]);

        $publication = app(ProductionPublicationCoordinator::class)->publish($this->admin->id);
        $packageManifest = json_decode((string) file_get_contents(
            config('cms.publication_package_root').'/'.$publication->package_path.'/manifest.json'
        ), true, flags: JSON_THROW_ON_ERROR);
        $packagedProject = collect($packageManifest['projects'])->firstWhere('id', $project->id);

        $this->assertSame($rollback['planHash'], $publication->plan_hash);
        $this->assertIsArray($packagedProject);
        $this->assertSame($originalProject['synopsis'], $packagedProject['synopsis']);
        $this->assertNotSame($unacceptedSynopsis, $packagedProject['synopsis']);
    }

    public function test_status_copy_covers_default_off_ready_progress_success_and_failure(): void
    {
        $coordinator = app(ProductionPublicationCoordinator::class);
        config(['publisher.production_automation_enabled' => false]);
        $this->assertSame('not_configured', $coordinator->status()['state']);
        config(['publisher.production_automation_enabled' => true]);
        $this->acceptChangedState();
        $this->assertSame('ready', $coordinator->status()['state']);
        Livewire::test(\App\Filament\Pages\Publication::class)
            ->assertSee('Bereit zur öffentlichen Veröffentlichung')
            ->assertSee('Jetzt öffentlich veröffentlichen');
        Http::fake(['*' => Http::response(null, 204)]);
        $publication = $coordinator->publish($this->admin->id);
        $this->assertSame('queued', $coordinator->status()['state']);
        Livewire::test(\App\Filament\Pages\Publication::class)
            ->assertSee('Veröffentlichung wird gestartet')
            ->assertSee('Veröffentlichung Nr. '.$publication->sequence);
        $service = app(\App\Services\ProductionPublicationService::class);
        $publication = $service->claimForRunner($publication, 'github-123')['publication'];
        $publication = $service->resumeClaimedWorkflow($publication, 'github-123')['publication'];
        $publication = $service->markBuilding($publication);
        $this->assertSame('in_progress', $coordinator->status()['state']);
        $this->travel(46)->minutes();
        $status = $coordinator->status();
        $this->assertSame('failed', $status['state']);
        $this->assertTrue($status['ready']);
        $this->assertSame('workflow_timeout', $publication->refresh()->failure_code);
        $this->assertStringContainsString('bisherige öffentliche Stand bleibt erhalten', $status['message']);
    }

    private function acceptChangedState(): array
    {
        $project = Project::query()->where('status', 'published')->firstOrFail();
        $project->update(['synopsis' => $project->synopsis.' Accepted production change']);
        $candidate = app(ManifestExporter::class)->export(false, false, $this->admin->id);
        $review = app(FrontendPublication::class)->review($candidate['hash'], $this->admin->id);
        $applied = app(FrontendPublication::class)->apply($review['planHash'], $this->admin->id);
        $this->assertSame('success', $applied['status']);
        return ['candidateHash' => $candidate['hash'], 'planHash' => $review['planHash']];
    }

    private function readActiveManifest(): array
    {
        return json_decode((string) file_get_contents(config('cms.manifest')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeActiveManifest(array $manifest): void
    {
        file_put_contents(
            config('cms.manifest'),
            json_encode(app(ContentValidator::class)->normalizeJsonObjectSlots($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    private function referencedAssetIds(array $manifest): array
    {
        $ids = [];
        $add = static function (mixed $assetId) use (&$ids): void {
            if (is_string($assetId) && $assetId !== '') $ids[$assetId] = true;
        };
        foreach ($manifest['projects'] as $project) {
            foreach ($project['memberships'] as $membership) {
                $add($membership['coverAssetId']);
                $add($membership['hoverAssetId'] ?? null);
            }
            foreach ($project['gallery'] as $assetId) $add($assetId);
            $add($project['localTrailerAssetId'] ?? null);
            $add($project['localTrailerPosterId'] ?? null);
        }
        foreach ($manifest['tracks'] as $track) {
            $add($track['coverAssetId'] ?? null);
            $add($track['audioAssetId']);
        }
        foreach ($manifest['site']['mediaSlots'] as $slot) {
            $add($slot['image']);
            $add($slot['video']);
            $add($slot['poster']);
        }
        $add($manifest['site']['collective']['logoAssetId'] ?? null);
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }
}
