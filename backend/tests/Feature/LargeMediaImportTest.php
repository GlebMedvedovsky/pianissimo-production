<?php

namespace Tests\Feature;

use App\Filament\Pages\Help;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\Pages\ListMediaAssets;
use App\Models\{AuditEvent, MediaAsset, User};
use App\Services\{LargeMediaImportService, MediaStorage};
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class LargeMediaImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;
    private string $incoming;
    private string $originals;
    private array $storageCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/large-media-import-'.bin2hex(random_bytes(6)));
        $this->incoming = $this->fixtureRoot.'/incoming';
        $this->originals = $this->fixtureRoot.'/originals';
        File::ensureDirectoryExists($this->incoming, 0750);
        File::ensureDirectoryExists($this->originals, 0750);
        config([
            'cms.media_incoming_root' => $this->incoming,
            'cms.media_originals_root' => $this->originals,
            'cms.media_upload_max_kb' => 1,
            'cms.media_import_max_kb' => 8,
            'cms.media_max_kb' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->storageCleanup as $relative) File::deleteDirectory(storage_path('app/private/'.$relative));
        if (isset($this->fixtureRoot)) File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_owner_list_and_import_accept_file_above_browser_limit_and_create_canonical_media(): void
    {
        $source = $this->incoming.'/large-poster.png';
        file_put_contents($source, $this->png(2048));
        $service = app(LargeMediaImportService::class);

        $entries = $service->pending();
        $this->assertCount(1, $entries);
        $this->assertSame('large-poster.png', $entries[0]['name']);
        $this->assertSame('image/png', $entries[0]['mime']);
        $this->assertStringContainsString('KiB', $entries[0]['label']);
        $this->assertArrayHasKey('large-poster.png', $service->options());

        $asset = $service->import('large-poster.png', $this->attributes('sftp-large-poster'));

        $this->assertSame('image', $asset->type);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertGreaterThan(1024, $asset->file_size);
        $this->assertMatchesRegularExpression('#^media/originals/sftp-large-poster/[a-f0-9]{64}\.png$#', $asset->storage_path);
        $this->assertSame($asset->sha256, hash_file('sha256', app(MediaStorage::class)->resolveStored($asset->storage_path)));
        $this->assertFileDoesNotExist($source);
        $this->assertSame([], $service->pending());
        $this->assertTrue(AuditEvent::where('object_type', 'media')->where('object_id', $asset->id)->where('action', 'sftp-import')->exists());
    }

    public function test_selector_is_server_generated_and_rejects_traversal_encoded_and_absolute_paths(): void
    {
        $service = app(LargeMediaImportService::class);
        foreach (['../outside.png', '..%2Foutside.png', '%2e%2e-outside.png', '/etc/passwd', 'C:\\Windows\\file.png', 'folder/file.png'] as $selection) {
            $this->assertDomain(fn () => $service->import($selection, $this->attributes('bad-selection')), 'ungültig');
        }
        $this->assertDatabaseMissing('media_assets', ['id' => 'bad-selection']);
    }

    public function test_list_omits_and_import_rejects_symlinks_directories_special_files_and_unsupported_types(): void
    {
        file_put_contents($this->incoming.'/unsupported.txt', 'not media');
        File::ensureDirectoryExists($this->incoming.'/directory.png');
        $outside = $this->fixtureRoot.'/outside.png';
        file_put_contents($outside, $this->png());
        $link = $this->incoming.'/linked.png';
        if (! symlink($outside, $link)) $this->markTestSkipped('The test environment cannot create symbolic links.');

        $service = app(LargeMediaImportService::class);
        $this->assertSame([], $service->pending());
        foreach (['unsupported.txt', 'directory.png', 'linked.png'] as $name) {
            $this->assertDomain(fn () => $service->import($name, $this->attributes('invalid-'.pathinfo($name, PATHINFO_FILENAME))), 'Datei');
        }
        $this->assertFileExists($this->incoming.'/unsupported.txt');
        $this->assertDatabaseCount('media_assets', 0);

        if (function_exists('posix_mkfifo')) {
            $fifo = $this->incoming.'/pipe.mp4';
            if (posix_mkfifo($fifo, 0600)) {
                $this->assertDomain(fn () => $service->import('pipe.mp4', $this->attributes('invalid-pipe')), 'reguläre Datei');
            }
        }
    }

    public function test_unsupported_content_never_creates_record_or_promoted_original_and_source_is_preserved(): void
    {
        $source = $this->incoming.'/pretend-video.mp4';
        file_put_contents($source, 'plain text pretending to be video');
        $this->assertDomain(
            fn () => app(LargeMediaImportService::class)->import('pretend-video.mp4', $this->attributes('unsupported-media')),
            'Dateityp',
        );
        $this->assertFileExists($source);
        $this->assertDatabaseMissing('media_assets', ['id' => 'unsupported-media']);
        $this->assertDirectoryDoesNotExist($this->originals.'/unsupported-media');
        $this->assertSame([], glob($this->originals.'/pending/sftp-*') ?: []);
    }

    public function test_source_change_during_streamed_import_fails_closed_and_preserves_source(): void
    {
        $source = $this->incoming.'/still-uploading.png';
        file_put_contents($source, $this->png(2048));
        $this->assertDomain(
            fn () => app(LargeMediaImportService::class)->import(
                'still-uploading.png',
                $this->attributes('changing-media'),
                function (string $path): void {
                    $this->assertDatabaseMissing('media_assets', ['id' => 'changing-media']);
                    file_put_contents($path, 'more', FILE_APPEND);
                },
            ),
            'während des Imports verändert',
        );
        $this->assertFileExists($source);
        $this->assertDatabaseMissing('media_assets', ['id' => 'changing-media']);
        $this->assertDirectoryDoesNotExist($this->originals.'/changing-media');
        $this->assertSame([], glob($this->originals.'/pending/sftp-*') ?: []);
    }

    public function test_failure_after_canonical_promotion_removes_orphan_and_allows_same_id_retry(): void
    {
        $source = $this->incoming.'/post-promotion.png';
        file_put_contents($source, $this->png());
        $service = app(LargeMediaImportService::class);

        $this->assertDomain(
            fn () => $service->import(
                'post-promotion.png',
                $this->attributes('post-promotion-media'),
                null,
                function (array $metadata): void {
                    $this->assertFileExists(app(MediaStorage::class)->resolveStored($metadata['storage_path']));
                    $this->assertDatabaseMissing('media_assets', ['id' => 'post-promotion-media']);
                    throw new DomainException('Injected post-promotion failure.');
                },
            ),
            'Injected post-promotion failure',
        );

        $this->assertFileExists($source);
        $this->assertDatabaseMissing('media_assets', ['id' => 'post-promotion-media']);
        $this->assertDirectoryDoesNotExist($this->originals.'/post-promotion-media');
        $this->assertSame([], glob($this->originals.'/pending/sftp-*') ?: []);
        $this->assertSame([], glob($this->incoming.'/.pianissimo-importing-*') ?: []);

        $asset = $service->import('post-promotion.png', $this->attributes('post-promotion-media'));
        $this->assertSame('post-promotion-media', $asset->id);
        $this->assertFileDoesNotExist($source);
    }

    public function test_transaction_failure_restores_claim_and_removes_promoted_media_before_retry(): void
    {
        $source = $this->incoming.'/transaction-failure.png';
        $sourceSha = hash('sha256', $this->png());
        file_put_contents($source, $this->png());
        $service = app(LargeMediaImportService::class);

        $this->assertDomain(
            fn () => $service->import(
                'transaction-failure.png',
                $this->attributes('transaction-failure-media'),
                null,
                null,
                function (MediaAsset $asset, string $claimedSource): void {
                    $this->assertSame('transaction-failure-media', $asset->id);
                    $this->assertDatabaseHas('media_assets', ['id' => 'transaction-failure-media']);
                    $this->assertFileExists($claimedSource);
                    throw new DomainException('Injected transaction failure.');
                },
            ),
            'Injected transaction failure',
        );

        $this->assertDatabaseMissing('media_assets', ['id' => 'transaction-failure-media']);
        $this->assertFalse(AuditEvent::where('object_id', 'transaction-failure-media')->exists());
        $this->assertFileExists($source);
        $this->assertSame($sourceSha, hash_file('sha256', $source));
        $this->assertDirectoryDoesNotExist($this->originals.'/transaction-failure-media');
        $this->assertSame([], glob($this->originals.'/pending/sftp-*') ?: []);
        $this->assertSame([], glob($this->incoming.'/.pianissimo-importing-*') ?: []);

        $asset = $service->import('transaction-failure.png', $this->attributes('transaction-failure-media'));
        $this->assertSame('transaction-failure-media', $asset->id);
        $this->assertFileDoesNotExist($source);
    }

    public function test_server_import_and_application_limits_are_independent_and_leave_no_partial_media(): void
    {
        $source = $this->incoming.'/too-large.png';
        file_put_contents($source, $this->png(2048));
        config(['cms.media_import_max_kb' => 1]);
        $this->assertDomain(fn () => app(LargeMediaImportService::class)->import('too-large.png', $this->attributes('too-large')), 'Import-Limit');
        $this->assertFileExists($source);
        $this->assertDatabaseMissing('media_assets', ['id' => 'too-large']);
        $this->assertDirectoryDoesNotExist($this->originals.'/too-large');

        config(['cms.media_import_max_kb' => 8, 'cms.media_max_kb' => 1]);
        $this->assertDomain(fn () => app(LargeMediaImportService::class)->import('too-large.png', $this->attributes('app-too-large')), 'Import-Limit');
        $this->assertDatabaseMissing('media_assets', ['id' => 'app-too-large']);
    }

    public function test_duplicate_retry_cannot_replace_existing_media_or_consume_new_source(): void
    {
        $source = $this->incoming.'/retry.png';
        file_put_contents($source, $this->png());
        $service = app(LargeMediaImportService::class);
        $first = $service->import('retry.png', $this->attributes('retry-media'));
        file_put_contents($source, $this->png());

        $this->assertDomain(fn () => $service->import('retry.png', $this->attributes('retry-media')), 'bereits vergeben');
        $this->assertFileExists($source);
        $this->assertSame(1, MediaAsset::withTrashed()->whereKey('retry-media')->count());
        $this->assertSame($first->sha256, MediaAsset::findOrFail('retry-media')->sha256);
    }

    public function test_ordinary_browser_staging_still_uses_media_storage_and_canonical_root(): void
    {
        config(['cms.media_upload_max_kb' => 8]);
        $id = 'browser-upload-'.bin2hex(random_bytes(3));
        $directory = 'media/originals/'.$id;
        $this->storageCleanup[] = $directory;
        File::ensureDirectoryExists(storage_path('app/private/'.$directory), 0750);
        $relative = $directory.'/upload.png';
        file_put_contents(storage_path('app/private/'.$relative), $this->png());

        $metadata = app(MediaStorage::class)->inspectStored($relative, $id, 'browser.png');

        $this->assertSame('image/png', $metadata['mime_type']);
        $this->assertFileExists(app(MediaStorage::class)->resolveStored($metadata['storage_path']));
        $this->assertFileDoesNotExist(storage_path('app/private/'.$relative));
    }

    public function test_incoming_root_rejects_outside_paths_and_descendant_symlinks(): void
    {
        $outside = dirname(dirname(base_path(), 2)).'/outside-media-'.bin2hex(random_bytes(4));
        config(['cms.media_incoming_root' => $outside]);
        $this->assertDomain(fn () => app(LargeMediaImportService::class)->pending(), 'unsicher konfiguriert');
        $this->assertDirectoryDoesNotExist($outside);

        $target = $this->fixtureRoot.'/target';
        $link = $this->fixtureRoot.'/incoming-link';
        File::ensureDirectoryExists($target, 0750);
        if (! symlink($target, $link)) $this->markTestSkipped('The test environment cannot create symbolic links.');
        config(['cms.media_incoming_root' => $link]);
        $this->assertDomain(fn () => app(LargeMediaImportService::class)->pending(), 'unsicher konfiguriert');
    }

    public function test_ssh_fastcgi_runtime_roots_and_no_ancestor_probe_are_preserved(): void
    {
        $service = app(LargeMediaImportService::class);
        $rootMethod = new ReflectionMethod($service, 'runtimeSiteRoot');
        $this->assertSame('/pianissimo-production.de', $rootMethod->invoke($service, '/pianissimo-production.de/app/backend'));
        $runtime = '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de';
        $this->assertSame($runtime, $rootMethod->invoke($service, $runtime.'/app/backend'));

        $probes = [];
        $method = new ReflectionMethod($service, 'containsSymlinkComponent');
        $this->assertFalse($method->invoke($service, $runtime.'/private/incoming/media', $runtime, function (string $path) use (&$probes): bool {
            $probes[] = $path;
            return false;
        }));
        $this->assertSame($runtime, $probes[0]);
        $this->assertNotContains('/var', $probes);
        $this->assertNotContains('/var/www', $probes);
        $this->assertNotContains('/var/www/vhosts', $probes);
        foreach ($probes as $probe) $this->assertTrue($probe === $runtime || str_starts_with($probe, $runtime.'/'));
    }

    public function test_media_page_is_authenticated_and_exposes_import_refresh_and_deep_help(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get(MediaAssetResource::getUrl('index'))->assertRedirect();
        $this->get(Help::getUrl(['article' => 'grosse-dateien-sftp']))->assertRedirect();
        $user = User::create(['name' => 'SFTP Owner', 'email' => 'sftp-owner@example.test', 'password' => bin2hex(random_bytes(20))]);
        $this->actingAs($user);
        $this->get(MediaAssetResource::getUrl('index'))->assertOk()
            ->assertSee('Große Datei importieren')->assertSee('SFTP-Liste aktualisieren')->assertSee('Sehr große Datei? Anleitung öffnen');
        Livewire::test(ListMediaAssets::class)->assertActionExists('importLarge')->assertActionExists('refreshLargeImports')->assertActionExists('largeMediaHelp');

        $source = $this->incoming.'/ui-import.png';
        file_put_contents($source, $this->png());
        Livewire::test(ListMediaAssets::class)
            ->callAction('importLarge', data: [
                'incoming_file' => 'ui-import.png',
                ...$this->attributes('ui-import'),
            ])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('media_assets', ['id' => 'ui-import', 'original_name' => 'ui-import.png']);
        $this->assertFileDoesNotExist($source);

        Livewire::test(Help::class)->set('article', 'grosse-dateien-sftp')
            ->assertSee('Große Dateien per SFTP hochladen')->assertSee('FileZilla')->assertSee(LargeMediaImportService::SSH_INCOMING_PATH)
            ->assertSee('Große Datei importieren')->assertSee('SFTP-Liste aktualisieren');
    }

    private function attributes(string $id): array
    {
        return ['id' => $id, 'purpose' => 'other', 'alt' => 'SFTP QA', 'state' => 'temporary', 'rights_scope' => 'next_draft'];
    }

    private function png(int $minimumBytes = 0): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=');
        return $bytes.str_repeat("\0", max(0, $minimumBytes - strlen($bytes)));
    }

    private function assertDomain(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected DomainException containing: '.$message);
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
