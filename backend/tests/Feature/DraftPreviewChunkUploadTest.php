<?php

namespace Tests\Feature;

use App\Models\{DraftPreview, User};
use App\Services\{DraftPreviewPackageService, DraftPreviewResultService, DraftPreviewService, StaticReleaseArchive};
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DraftPreviewChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_MEDIA_BYTES = 'deterministic-preview-package-media';

    private string $fixtureRoot;
    private DraftPreview $preview;
    private array $package;
    private string $archive;
    private array $upload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/draft-preview-chunks-'.bin2hex(random_bytes(6)));
        config([
            'cms.source_revision' => str_repeat('a', 40),
            'cms.preview_storage_root' => $this->fixtureRoot.'/snapshots',
            'cms.preview_package_root' => $this->fixtureRoot.'/packages',
            'cms.preview_incoming_root' => $this->fixtureRoot.'/incoming',
            'cms.preview_result_root' => $this->fixtureRoot.'/results',
            'publisher.api_token' => str_repeat('p', 48),
        ]);
        $user = User::create(['name' => 'Chunk QA', 'email' => bin2hex(random_bytes(4)).'@example.test', 'password' => bin2hex(random_bytes(20))]);
        $identifier = bin2hex(random_bytes(16));
        $this->preview = DraftPreview::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', random_bytes(32)),
            'draft_hash' => str_repeat('b', 64),
            'snapshot_path' => 'draft-previews/'.$identifier,
            'media' => [],
            'expires_at' => now()->addMinutes(15),
        ]);
        File::ensureDirectoryExists($this->fixtureRoot.'/snapshots/'.$identifier, 0750);
        $this->package = $this->writePackage($identifier);
        [$archive, $digests] = $this->resultArchive($identifier);
        $minimum = DraftPreviewResultService::CHUNK_BYTES + 1024;
        $this->archive = $archive.str_repeat("\0", max(0, $minimum - strlen($archive)));
        $this->upload = [
            ...$digests,
            'archiveSha256' => hash('sha256', $this->archive),
            'chunkCount' => (int) ceil(strlen($this->archive) / DraftPreviewResultService::CHUNK_BYTES),
            'packageSha256' => $this->package['packageSha256'],
            'sourceRevision' => $this->package['sourceRevision'],
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot)) File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_authenticated_multi_chunk_upload_finalizes_only_after_complete_validation(): void
    {
        $preview = $this->preview;
        app()->instance(DraftPreviewService::class, new class($preview) extends DraftPreviewService {
            public function __construct(private readonly DraftPreview $preview) {}
            public function resolveForPublisher(string $identifier): DraftPreview { return $this->preview->fresh(); }
        });
        $results = app(DraftPreviewResultService::class);
        $chunks = str_split($this->archive, DraftPreviewResultService::CHUNK_BYTES);
        foreach ($chunks as $index => $chunk) {
            $headers = $this->headers(['X-Pianissimo-Chunk-Sha256' => hash('sha256', $chunk)]);
            $response = $this->call(
                'POST',
                '/api/publisher/v1/previews/'.basename($preview->snapshot_path).'/result/chunks/'.$index,
                [], [], [], $this->serverHeaders($headers), $chunk,
            );
            $response->assertCreated();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertTrue($response->headers->getCacheControlDirective('private'));
        }
        $this->assertFalse($results->ready($preview));
        $this->assertFileDoesNotExist($this->fixtureRoot.'/results/'.basename($preview->snapshot_path).'.json');

        $this->withHeaders($this->headers())
            ->post('/api/publisher/v1/previews/'.basename($preview->snapshot_path).'/result/finalize')
            ->assertCreated()->assertJson(['ready' => true]);

        $this->assertTrue($results->ready($preview));
        $this->assertSame('<!doctype html><script src="/_astro/app.js"></script><link rel="stylesheet" href="/_astro/app.css">', file_get_contents($results->page($preview, '/')['path']));
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/incoming/'.basename($preview->snapshot_path));

        $this->withHeaders($this->headers())
            ->post('/api/publisher/v1/previews/'.basename($preview->snapshot_path).'/result/finalize')
            ->assertOk()->assertJson(['ready' => true]);
        $this->assertDomain(fn () => $this->upload(0, $chunks[0]), 'bereits abgeschlossen');
        $conflicting = [...$this->upload, 'archiveSha256' => str_repeat('d', 64)];
        $this->assertDomain(fn () => $this->upload(0, $chunks[0], $conflicting), 'bereits abgeschlossen');
        $this->assertDomain(fn () => $results->finalize($preview, $conflicting), 'anderen Identität');
    }

    public function test_exact_chunk_retry_is_idempotent_but_an_altered_retry_is_rejected(): void
    {
        $chunk = substr($this->archive, 0, DraftPreviewResultService::CHUNK_BYTES);
        $this->assertTrue($this->upload(0, $chunk)['stored']);
        $this->assertFalse($this->upload(0, $chunk)['stored']);
        $altered = substr_replace($chunk, $chunk[0] === 'x' ? 'y' : 'x', 0, 1);
        $this->assertDomain(fn () => $this->upload(0, $altered), 'andere Daten');
    }

    public function test_waiting_readiness_uses_package_identity_without_rehashing_media_bytes(): void
    {
        $this->tamperPackageMedia();

        $this->assertFalse(app(DraftPreviewResultService::class)->ready($this->preview));
        $this->assertDomain(fn () => app(DraftPreviewPackageService::class)->verify($this->preview), 'Medium des Vorschau-Pakets wurde verändert');
    }

    public function test_chunk_upload_uses_package_identity_without_rehashing_media_bytes(): void
    {
        $this->tamperPackageMedia();
        $chunk = substr($this->archive, 0, DraftPreviewResultService::CHUNK_BYTES);

        $this->assertTrue($this->upload(0, $chunk)['stored']);
        $this->assertDomain(fn () => app(DraftPreviewPackageService::class)->verify($this->preview), 'Medium des Vorschau-Pakets wurde verändert');
    }

    public function test_installed_asset_serving_does_not_rehash_package_media_bytes(): void
    {
        foreach (str_split($this->archive, DraftPreviewResultService::CHUNK_BYTES) as $index => $chunk) $this->upload($index, $chunk);
        $this->assertTrue(app(DraftPreviewResultService::class)->finalize($this->preview, $this->upload));
        $this->tamperPackageMedia();

        $this->assertTrue(app(DraftPreviewResultService::class)->ready($this->preview));
        $asset = app(DraftPreviewResultService::class)->file($this->preview, '_astro/app.css');
        $this->assertSame('body{color:#111}', file_get_contents($asset['path']));
        $this->assertDomain(fn () => app(DraftPreviewPackageService::class)->verify($this->preview), 'Medium des Vorschau-Pakets wurde verändert');
    }

    public function test_finalization_still_rehashes_and_rejects_tampered_package_media(): void
    {
        foreach (str_split($this->archive, DraftPreviewResultService::CHUNK_BYTES) as $index => $chunk) $this->upload($index, $chunk);
        $this->tamperPackageMedia();

        $this->assertDomain(fn () => app(DraftPreviewResultService::class)->finalize($this->preview, $this->upload), 'Medium des Vorschau-Pakets wurde verändert');
        $this->assertFalse(app(DraftPreviewResultService::class)->ready($this->preview));
    }

    public function test_out_of_range_index_is_rejected(): void
    {
        $this->assertDomain(fn () => $this->upload($this->upload['chunkCount'], 'x'), 'außerhalb');
    }

    public function test_excessive_chunk_count_is_rejected(): void
    {
        $identity = [...$this->upload, 'chunkCount' => DraftPreviewResultService::MAX_CHUNKS + 1];
        $this->assertDomain(fn () => $this->upload(0, 'x', $identity), 'identität');
    }

    public function test_total_count_mismatch_is_rejected(): void
    {
        $chunk = substr($this->archive, 0, DraftPreviewResultService::CHUNK_BYTES);
        $this->upload(0, $chunk);
        $changed = [...$this->upload, 'chunkCount' => $this->upload['chunkCount'] + 1];
        $this->assertDomain(fn () => $this->upload(1, $chunk, $changed), 'widersprechen');
    }

    public function test_oversized_chunk_is_rejected(): void
    {
        $bytes = str_repeat('x', DraftPreviewResultService::CHUNK_BYTES + 1);
        $single = [...$this->upload, 'chunkCount' => 1, 'archiveSha256' => hash('sha256', $bytes)];
        $this->assertDomain(fn () => $this->upload(0, $bytes, $single), 'zu groß');
        $this->assertSame([], glob($this->fixtureRoot.'/incoming/'.basename($this->preview->snapshot_path).'/.chunk-*'));
    }

    public function test_missing_chunk_prevents_finalization_and_keeps_retryable_chunks(): void
    {
        $chunk = substr($this->archive, 0, DraftPreviewResultService::CHUNK_BYTES);
        $this->upload(0, $chunk);
        $this->assertDomain(fn () => app(DraftPreviewResultService::class)->finalize($this->preview, $this->upload), 'noch nicht vollständig');
        $this->assertFileExists($this->fixtureRoot.'/incoming/'.basename($this->preview->snapshot_path).'/chunk-000000.part');
        $this->assertFalse(app(DraftPreviewResultService::class)->ready($this->preview));
    }

    public function test_complete_archive_sha_mismatch_is_rejected_and_failed_upload_is_cleaned(): void
    {
        $wrong = [...$this->upload, 'archiveSha256' => str_repeat('c', 64)];
        foreach (str_split($this->archive, DraftPreviewResultService::CHUNK_BYTES) as $index => $chunk) $this->upload($index, $chunk, $wrong);
        $this->assertDomain(fn () => app(DraftPreviewResultService::class)->finalize($this->preview, $wrong), 'Archiv-Prüfsumme');
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/incoming/'.basename($this->preview->snapshot_path));
        $this->assertFalse(app(DraftPreviewResultService::class)->ready($this->preview));
    }

    public function test_stale_and_expired_previews_are_rejected(): void
    {
        $this->preview->update(['invalidated_at' => now()]);
        $this->assertDomain(fn () => $this->upload(0, 'x'), 'nicht mehr gültig');
        $this->preview->update(['invalidated_at' => null, 'expires_at' => now()->subSecond()]);
        $this->assertDomain(fn () => $this->upload(0, 'x'), 'abgelaufen');
    }

    public function test_package_and_source_identity_mismatches_are_rejected(): void
    {
        $this->assertDomain(fn () => $this->upload(0, 'x', [...$this->upload, 'packageSha256' => str_repeat('d', 64)]), 'Paket oder Quellstand');
        $this->assertDomain(fn () => $this->upload(0, 'x', [...$this->upload, 'sourceRevision' => str_repeat('e', 40)]), 'Paket oder Quellstand');
    }

    public function test_symlinked_incoming_identifier_is_rejected(): void
    {
        File::ensureDirectoryExists($this->fixtureRoot.'/incoming', 0750);
        File::ensureDirectoryExists($this->fixtureRoot.'/outside', 0750);
        $link = $this->fixtureRoot.'/incoming/'.basename($this->preview->snapshot_path);
        if (! symlink($this->fixtureRoot.'/outside', $link)) $this->markTestSkipped('The test environment cannot create symbolic links.');
        $this->assertDomain(fn () => $this->upload(0, 'x'), 'unsicher');
        unlink($link);
    }

    public function test_invalidation_cleans_incomplete_chunks_snapshot_and_package(): void
    {
        $chunk = substr($this->archive, 0, DraftPreviewResultService::CHUNK_BYTES);
        $this->upload(0, $chunk);
        app(DraftPreviewService::class)->invalidateForUser($this->preview->user_id);
        $identifier = basename($this->preview->snapshot_path);
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/incoming/'.$identifier);
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/snapshots/'.$identifier);
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/packages/'.$identifier);
        $this->assertFileDoesNotExist($this->fixtureRoot.'/incoming/.upload-'.$identifier.'.lock');
        $this->assertNotNull($this->preview->fresh()->invalidated_at);
    }

    public function test_expiry_purge_cleans_incomplete_chunks_and_private_material(): void
    {
        $chunk = substr($this->archive, 0, DraftPreviewResultService::CHUNK_BYTES);
        $this->upload(0, $chunk);
        $this->preview->update(['expires_at' => now()->subSecond()]);

        app(DraftPreviewService::class)->purgeExpired();

        $identifier = basename($this->preview->snapshot_path);
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/incoming/'.$identifier);
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/snapshots/'.$identifier);
        $this->assertDirectoryDoesNotExist($this->fixtureRoot.'/packages/'.$identifier);
        $this->assertFileDoesNotExist($this->fixtureRoot.'/incoming/.upload-'.$identifier.'.lock');
        $this->assertDatabaseMissing('draft_previews', ['id' => $this->preview->id]);
    }

    private function upload(int $index, string $bytes, ?array $identity = null): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes); rewind($stream);
        try {
            return app(DraftPreviewResultService::class)->uploadChunk($this->preview, $index, $stream, $identity ?? $this->upload, hash('sha256', $bytes));
        } finally {
            fclose($stream);
        }
    }

    private function headers(array $extra = []): array
    {
        return [...$extra,
            'Authorization' => 'Bearer '.str_repeat('p', 48),
            'Content-Type' => 'application/octet-stream',
            'X-Pianissimo-Archive-Sha256' => $this->upload['archiveSha256'],
            'X-Pianissimo-Chunk-Count' => (string) $this->upload['chunkCount'],
            'X-Pianissimo-Files-Sha256' => $this->upload['releaseFilesSha256'],
            'X-Pianissimo-Metadata-Sha256' => $this->upload['releaseMetadataSha256'],
            'X-Pianissimo-Package-Sha256' => $this->upload['packageSha256'],
            'X-Pianissimo-Source-Revision' => $this->upload['sourceRevision'],
        ];
    }

    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }
        return $server;
    }

    private function writePackage(string $identifier): array
    {
        $directory = $this->fixtureRoot.'/packages/'.$identifier;
        File::ensureDirectoryExists($directory.'/media/preview', 0750);
        $manifestBytes = "{}\n";
        $media = [[
            'assetId' => 'package-test-image',
            'publicPath' => 'media/preview/package-test-image.webp',
            'sha256' => hash('sha256', self::PACKAGE_MEDIA_BYTES),
            'size' => strlen(self::PACKAGE_MEDIA_BYTES),
            'type' => 'image',
        ]];
        $digest = [
            'contractVersion' => DraftPreviewPackageService::CONTRACT_VERSION,
            'draftHash' => $this->preview->draft_hash,
            'manifestSha256' => hash('sha256', $manifestBytes),
            'media' => $media,
            'sourceRevision' => (string) config('cms.source_revision'),
        ];
        $metadata = [
            ...$digest,
            'createdAt' => $this->preview->created_at->toIso8601String(),
            'packageSha256' => hash('sha256', $this->canonicalJson($digest)),
            'previewId' => $this->preview->id,
            'previewIdentifier' => $identifier,
        ];
        file_put_contents($directory.'/manifest.json', $manifestBytes);
        file_put_contents($directory.'/preview.json', $this->canonicalJson($metadata)."\n");
        file_put_contents($directory.'/media/preview/package-test-image.webp', self::PACKAGE_MEDIA_BYTES);
        return app(DraftPreviewPackageService::class)->verify($this->preview);
    }

    private function resultArchive(string $identifier): array
    {
        $public = [
            ['index.html', '<!doctype html><script src="/_astro/app.js"></script><link rel="stylesheet" href="/_astro/app.css">'],
            ['_astro/app.css', 'body{color:#111}'],
            ['_astro/app.js', 'console.log("preview")'],
            ['favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            ['media/preview/package-test-image.webp', self::PACKAGE_MEDIA_BYTES],
            ['robots.txt', "User-agent: *\nDisallow: /\n"],
        ];
        $inventory = array_map(static fn (array $entry): array => ['path' => $entry[0], 'sha256' => hash('sha256', $entry[1]), 'size' => strlen($entry[1])], $public);
        usort($inventory, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $inventoryBytes = json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $metadata = [
            'releaseContractVersion' => 1,
            'publicationId' => $identifier,
            'sequence' => $this->preview->id,
            'sourceRevision' => $this->package['sourceRevision'],
            'packageSha256' => $this->package['packageSha256'],
            'manifestSha256' => $this->package['manifestSha256'],
            'releaseFilesSha256' => hash('sha256', $inventoryBytes),
            'media' => [[
                'publicPath' => 'media/preview/package-test-image.webp',
                'sha256' => hash('sha256', self::PACKAGE_MEDIA_BYTES),
                'size' => strlen(self::PACKAGE_MEDIA_BYTES),
                'type' => 'image',
            ]],
        ];
        $metadataBytes = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $entries = [...$public, [StaticReleaseArchive::FILES_FILE, $inventoryBytes], [StaticReleaseArchive::METADATA_FILE, $metadataBytes]];
        return [$this->tar($entries), ['releaseFilesSha256' => $metadata['releaseFilesSha256'], 'releaseMetadataSha256' => hash('sha256', $metadataBytes)]];
    }

    private function tar(array $entries): string
    {
        $archive = '';
        foreach ($entries as [$path, $data]) {
            $header = str_repeat("\0", 512);
            $this->putHeader($header, 0, 100, $path);
            $this->putHeader($header, 100, 8, $this->octal(0644, 8));
            $this->putHeader($header, 108, 8, $this->octal(0, 8));
            $this->putHeader($header, 116, 8, $this->octal(0, 8));
            $this->putHeader($header, 124, 12, $this->octal(strlen($data), 12));
            $this->putHeader($header, 136, 12, $this->octal(0, 12));
            $this->putHeader($header, 148, 8, str_repeat(' ', 8));
            $this->putHeader($header, 156, 1, '0');
            $this->putHeader($header, 257, 6, "ustar\0");
            $this->putHeader($header, 263, 2, '00');
            $this->putHeader($header, 148, 8, str_pad(decoct(array_sum(unpack('C*', $header))), 6, '0', STR_PAD_LEFT)."\0 ");
            $archive .= $header.$data.str_repeat("\0", (512 - (strlen($data) % 512)) % 512);
        }
        return $archive.str_repeat("\0", 1024);
    }

    private function octal(int $value, int $length): string { return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0"; }
    private function putHeader(string &$target, int $offset, int $length, string $value): void { $target = substr_replace($target, str_pad(substr($value, 0, $length), $length, "\0"), $offset, $length); }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) $value = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            else { ksort($value, SORT_STRING); foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item); }
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING); foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item); return $value;
    }

    private function tamperPackageMedia(): void
    {
        $path = $this->fixtureRoot.'/packages/'.basename($this->preview->snapshot_path).'/media/preview/package-test-image.webp';
        file_put_contents($path, str_repeat('x', strlen(self::PACKAGE_MEDIA_BYTES)));
    }

    private function assertDomain(callable $callback, string $message): void
    {
        try { $callback(); $this->fail('Expected DomainException was not thrown.'); }
        catch (DomainException $exception) { $this->assertStringContainsString($message, $exception->getMessage()); }
    }
}
