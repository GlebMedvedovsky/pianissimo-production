<?php

namespace Tests\Feature;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use App\Models\Setting;
use App\Services\ProductionPublicationService;
use App\Services\StaticReleaseActivator;
use App\Services\StaticReleaseArchive;
use App\Services\StaticReleaseFilesystem;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StaticReleaseActivatorTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $incoming;
    private string $static;
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/static-release-'.bin2hex(random_bytes(5)));
        $this->incoming = $this->root.DIRECTORY_SEPARATOR.'incoming';
        $this->static = $this->root.DIRECTORY_SEPARATOR.'static';
        $this->public = $this->root.DIRECTORY_SEPARATOR.'httpdocs';
        File::ensureDirectoryExists($this->incoming);
        File::ensureDirectoryExists($this->static.DIRECTORY_SEPARATOR.'releases');
        File::ensureDirectoryExists($this->public);
        file_put_contents($this->public.DIRECTORY_SEPARATOR.'index.html', 'previous-public-site');
        file_put_contents($this->incoming.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::INCOMING_MARKER, StaticReleaseFilesystem::INCOMING_MARKER_CONTENT."\n");
        file_put_contents($this->static.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::ROOT_MARKER, StaticReleaseFilesystem::ROOT_MARKER_CONTENT."\n");
        config([
            'cms.publication_driver' => 'package',
            'cms.production_incoming_root' => $this->incoming,
            'cms.static_release_root' => $this->static,
            'cms.public_site_root' => $this->public,
        ]);
    }

    protected function tearDown(): void
    {
        $current = $this->static.DIRECTORY_SEPARATOR.'current';
        if (is_link($current)) {
            @unlink($current);
        }
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_archive_sha_publication_sequence_runner_and_markers_are_enforced(): void
    {
        [$publication, $archive, $actualSha] = $this->release(1, declaredSha: str_repeat('a', 64));
        $this->expectFailure(fn () => $this->activate($publication, $archive, str_repeat('a', 64)), 'SHA-256');
        $this->assertSame('previous-public-site', file_get_contents($this->public.DIRECTORY_SEPARATOR.'index.html'));

        [$publication, $archive, $sha] = $this->release(2);
        $wrongSequenceArchive = $this->filename(3, $publication->publication_id, $sha);
        copy($this->incoming.DIRECTORY_SEPARATOR.$archive, $this->incoming.DIRECTORY_SEPARATOR.$wrongSequenceArchive);
        $this->expectFailure(fn () => app(StaticReleaseActivator::class)->activate($publication->publication_id, 3, $wrongSequenceArchive, $sha, 'runner-test'), 'identity');
        $this->expectFailure(fn () => app(StaticReleaseActivator::class)->activate($publication->publication_id, 2, $archive, $sha, 'runner-other'), 'runner');

        @unlink($this->incoming.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::INCOMING_MARKER);
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'marker');
        file_put_contents($this->incoming.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::INCOMING_MARKER, StaticReleaseFilesystem::INCOMING_MARKER_CONTENT);
        @unlink($this->static.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::ROOT_MARKER);
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'marker');
    }

    public function test_archive_rejects_traversal_links_and_special_entries(): void
    {
        foreach ([
            ['../escape.txt', 'bad', '0'],
            ['media/link.jpg', '', '2'],
            ['media/hard.jpg', '', '1'],
            ['media/device', '', '3'],
            ['media/fifo', '', '6'],
        ] as $index => $unsafe) {
            [$publication, $archive, $sha] = $this->release($index + 1, static fn (array $entries): array => [$unsafe]);
            $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), $index === 0 ? 'path' : 'special');
        }
    }

    public function test_extracted_release_contract_rejects_executables_private_content_missing_index_media_and_wrong_metadata(): void
    {
        $cases = [
            'executable' => static function (array $entries): array { $entries[] = ['shell.php', '<?php', '0']; return $entries; },
            'environment' => static function (array $entries): array { $entries[] = ['.env', 'SECRET=x', '0']; return $entries; },
            'private' => static function (array $entries): array { $entries[] = ['backend/config.json', '{}', '0']; return $entries; },
            'index' => static fn (array $entries): array => array_values(array_filter($entries, static fn (array $entry): bool => $entry[0] !== 'index.html')),
            'media' => static fn (array $entries): array => array_values(array_filter($entries, static fn (array $entry): bool => $entry[0] !== 'media/release.jpg')),
            'metadata' => static function (array $entries): array {
                foreach ($entries as &$entry) {
                    if ($entry[0] === StaticReleaseArchive::METADATA_FILE) {
                        $metadata = json_decode($entry[1], true, flags: JSON_THROW_ON_ERROR);
                        $metadata['publicationId'] = '12345678-1234-4234-8234-123456789abc';
                        $entry[1] = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                    }
                }
                return $entries;
            },
        ];
        $sequence = 1;
        foreach ($cases as $mutator) {
            [$publication, $archive, $sha] = $this->release($sequence++, $mutator);
            $this->expectFailure(fn () => $this->activate($publication, $archive, $sha));
        }
    }

    public function test_complete_release_metadata_bytes_and_strict_schema_are_authenticated(): void
    {
        $mutations = [
            'media modified' => static function (array &$metadata): void { $metadata['media'][0]['size']++; },
            'media removed' => static function (array &$metadata): void { $metadata['media'] = []; },
            'unknown key' => static function (array &$metadata): void { $metadata['unexpected'] = true; },
            'source revision' => static function (array &$metadata): void { $metadata['sourceRevision'] = str_repeat('a', 40); },
            'release files digest' => static function (array &$metadata): void { $metadata['releaseFilesSha256'] = str_repeat('b', 64); },
            'package digest' => static function (array &$metadata): void { $metadata['packageSha256'] = str_repeat('c', 64); },
            'manifest digest' => static function (array &$metadata): void { $metadata['manifestSha256'] = str_repeat('d', 64); },
        ];

        $sequence = 1;
        foreach ($mutations as $label => $mutation) {
            [$publication, $archive, $sha] = $this->release($sequence++, static function (array $entries) use ($mutation): array {
                foreach ($entries as &$entry) {
                    if ($entry[0] !== StaticReleaseArchive::METADATA_FILE) {
                        continue;
                    }
                    $metadata = json_decode($entry[1], true, flags: JSON_THROW_ON_ERROR);
                    $mutation($metadata);
                    $entry[1] = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                }
                unset($entry);

                return $entries;
            });
            $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'metadata digest');
        }
    }

    public function test_unknown_metadata_key_is_rejected_even_when_its_digest_is_recorded(): void
    {
        [$publication, $archive, $sha] = $this->release(1, static function (array $entries): array {
            foreach ($entries as &$entry) {
                if ($entry[0] === StaticReleaseArchive::METADATA_FILE) {
                    $metadata = json_decode($entry[1], true, flags: JSON_THROW_ON_ERROR);
                    $metadata['unexpected'] = true;
                    $entry[1] = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                }
            }
            unset($entry);

            return $entries;
        }, trustMutatedMetadata: true);

        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'metadata does not match');
    }

    public function test_text_reference_to_missing_media_is_rejected(): void
    {
        [$publication, $archive, $sha] = $this->release(1, static function (array $entries): array {
            foreach ($entries as &$entry) {
                if ($entry[0] === 'index.html') {
                    $entry[1] = '<img src="/media/not-present.jpg">';
                }
            }
            return $entries;
        }, trustMutatedMetadata: true, resignMutatedFiles: true);
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'missing media');
    }

    public function test_first_activation_and_idempotent_retry_work_and_only_one_publication_is_active(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$first, $firstArchive, $firstSha] = $this->release(1);
        $result = $this->activate($first, $firstArchive, $firstSha);
        $this->assertSame('activated', $result['result']);
        $this->assertSame('releases/1-'.$first->publication_id, readlink($this->static.DIRECTORY_SEPARATOR.'current'));
        $this->assertSame(ProductionPublicationStatus::Active, $first->refresh()->status);
        $this->assertStringContainsString('/_astro/app.js', file_get_contents($this->public.DIRECTORY_SEPARATOR.'index.html'));
        $this->assertSame('release-media-1', file_get_contents($this->public.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'release.jpg'));
        $this->assertFileDoesNotExist($this->public.DIRECTORY_SEPARATOR.StaticReleaseArchive::METADATA_FILE);
        $this->assertFileDoesNotExist($this->public.DIRECTORY_SEPARATOR.StaticReleaseArchive::FILES_FILE);
        $this->assertFileDoesNotExist($this->public.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE);

        $retry = $this->activate($first, $firstArchive, $firstSha);
        $this->assertSame('already_active', $retry['result']);

        [$second, $secondArchive, $secondSha] = $this->release(2);
        $this->activate($second, $secondArchive, $secondSha);
        $this->assertSame('release-media-2', file_get_contents($this->public.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'release.jpg'));
        $backup = dirname($this->public).DIRECTORY_SEPARATOR.'.pianissimo-httpdocs-previous';
        $this->assertSame('release-media-1', file_get_contents($backup.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'release.jpg'));
        $this->assertSame(1, ProductionPublication::query()->where('status', ProductionPublicationStatus::Active->value)->count());
        $this->assertSame(ProductionPublicationStatus::Superseded, $first->refresh()->status);
        $this->assertSame($second->getKey(), ProductionPublication::currentActive()?->getKey());
    }

    public function test_retained_release_rejects_modified_missing_and_extra_static_files(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$publication, $archive, $sha] = $this->release(1);
        $this->activate($publication, $archive, $sha);
        $release = $this->static.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'1-'.$publication->publication_id;

        foreach (['index.html', '_astro/app.js', '_astro/app.css'] as $relative) {
            $path = $release.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $original = file_get_contents($path);
            file_put_contents($path, (string) $original.'tampered');
            $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'digest');
            file_put_contents($path, $original);
        }

        file_put_contents($release.DIRECTORY_SEPARATOR.'unexpected.txt', 'unexpected');
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'extra');
        @unlink($release.DIRECTORY_SEPARATOR.'unexpected.txt');

        $missing = $release.DIRECTORY_SEPARATOR.'robots.txt';
        $missingBytes = file_get_contents($missing);
        @unlink($missing);
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'missing');
        file_put_contents($missing, $missingBytes);

        $indexPath = $release.DIRECTORY_SEPARATOR.'index.html';
        $inventoryPath = $release.DIRECTORY_SEPARATOR.StaticReleaseArchive::FILES_FILE;
        $metadataPath = $release.DIRECTORY_SEPARATOR.StaticReleaseArchive::METADATA_FILE;
        $indexBytes = file_get_contents($indexPath);
        $inventoryBytes = file_get_contents($inventoryPath);
        $metadataBytes = file_get_contents($metadataPath);
        file_put_contents($indexPath, 'tampered and locally re-signed');
        $inventory = json_decode($inventoryBytes, true, flags: JSON_THROW_ON_ERROR);
        foreach ($inventory as &$entry) {
            if ($entry['path'] === 'index.html') {
                $entry['sha256'] = hash_file('sha256', $indexPath);
                $entry['size'] = filesize($indexPath);
            }
        }
        unset($entry);
        $forgedInventory = json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($inventoryPath, $forgedInventory);
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        $metadata['releaseFilesSha256'] = hash('sha256', $forgedInventory);
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'metadata');
        file_put_contents($indexPath, $indexBytes);
        file_put_contents($inventoryPath, $inventoryBytes);
        file_put_contents($metadataPath, $metadataBytes);

        $this->assertSame('already_active', $this->activate($publication, $archive, $sha)['result']);
    }

    public function test_rollback_rejects_a_tampered_retained_release(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$old, $oldArchive, $oldSha] = $this->release(1);
        $this->activate($old, $oldArchive, $oldSha);
        [$new, $newArchive, $newSha] = $this->release(2);
        $this->activate($new, $newArchive, $newSha);
        $oldIndex = $this->static.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'1-'.$old->publication_id.DIRECTORY_SEPARATOR.'index.html';
        file_put_contents($oldIndex, 'tampered retained index');

        $this->expectFailure(fn () => app(StaticReleaseActivator::class)->rollback($old->publication_id), 'digest');
    }

    public function test_idempotent_activation_and_rollback_reject_tampered_retained_metadata(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$old, $oldArchive, $oldSha] = $this->release(1);
        $this->activate($old, $oldArchive, $oldSha);
        $oldMetadata = $this->static.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'1-'.$old->publication_id.DIRECTORY_SEPARATOR.StaticReleaseArchive::METADATA_FILE;
        $original = file_get_contents($oldMetadata);
        $metadata = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $metadata['media'] = [];
        file_put_contents($oldMetadata, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        $this->expectFailure(fn () => $this->activate($old, $oldArchive, $oldSha), 'metadata digest');
        file_put_contents($oldMetadata, $original);

        [$new, $newArchive, $newSha] = $this->release(2);
        $this->activate($new, $newArchive, $newSha);
        $metadata = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $metadata['unknown'] = 'tampered';
        file_put_contents($oldMetadata, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        $this->expectFailure(fn () => app(StaticReleaseActivator::class)->rollback($old->publication_id), 'metadata digest');
    }

    public function test_activation_state_and_current_pointer_mismatches_fail_closed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$publication, $archive, $sha] = $this->release(1);
        $this->activate($publication, $archive, $sha);
        $stateFile = $this->static.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::STATE_FILE;
        $current = $this->static.DIRECTORY_SEPARATOR.'current';
        $validState = file_get_contents($stateFile);

        @unlink($stateFile);
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'disagree');

        file_put_contents($stateFile, json_encode([
            'version' => 1, 'highestActivatedSequence' => 1, 'activeRelease' => null,
            'previousRelease' => null, 'status' => 'stable',
        ], JSON_THROW_ON_ERROR)."\n");
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'disagree');

        file_put_contents($stateFile, json_encode([
            'version' => 1, 'highestActivatedSequence' => 1,
            'activeRelease' => 'releases/99-12345678-1234-4234-8234-123456789abc',
            'previousRelease' => null, 'status' => 'stable',
        ], JSON_THROW_ON_ERROR)."\n");
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'disagree');

        file_put_contents($stateFile, $validState);
        @unlink($current);
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'disagree');

        file_put_contents($stateFile, json_encode([
            'version' => 1, 'highestActivatedSequence' => 1, 'activeRelease' => null,
            'previousRelease' => null, 'status' => 'stable',
        ], JSON_THROW_ON_ERROR)."\n");
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'pristine');
    }

    public function test_existing_final_with_different_sha_and_unsafe_current_are_rejected(): void
    {
        [$publication, $archive, $sha] = $this->release(1);
        $final = $this->static.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'1-'.$publication->publication_id;
        File::ensureDirectoryExists($final);
        file_put_contents($final.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE, str_repeat('b', 64));
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'different archive');
        File::deleteDirectory($final);

        file_put_contents($this->static.DIRECTORY_SEPARATOR.'current', 'not a link');
        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'not a symlink');
        @unlink($this->static.DIRECTORY_SEPARATOR.'current');

        if (@symlink('../../outside', $this->static.DIRECTORY_SEPARATOR.'current')) {
            $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'unsafe target');
            @unlink($this->static.DIRECTORY_SEPARATOR.'current');
        }
    }

    public function test_atomic_switch_failure_leaves_previous_pointer_active(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$first, $firstArchive, $firstSha] = $this->release(1);
        $this->activate($first, $firstArchive, $firstSha);
        $previous = readlink($this->static.DIRECTORY_SEPARATOR.'current');
        [$second, $secondArchive, $secondSha] = $this->release(2);

        $filesystem = Mockery::mock(StaticReleaseFilesystem::class)->makePartial();
        $filesystem->shouldReceive('switchCurrent')->once()->andThrow(new RuntimeException('simulated atomic rename failure'));
        $this->app->instance(StaticReleaseFilesystem::class, $filesystem);
        $this->expectFailure(fn () => $this->activate($second, $secondArchive, $secondSha), 'simulated');
        $this->assertSame($previous, readlink($this->static.DIRECTORY_SEPARATOR.'current'));
        $this->assertSame($first->getKey(), ProductionPublication::currentActive()?->getKey());
        $this->assertSame('release-media-1', file_get_contents($this->public.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'release.jpg'));
    }

    public function test_partial_public_swap_failure_records_ambiguous_state_and_fails_closed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux directory and symlink semantics are verified in the backend runtime.');
        }
        [$publication, $archive, $sha] = $this->release(1);
        $moves = 0;
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('moveDirectory')->times(3)->andReturnUsing(
            static function (string $from, string $to) use (&$moves): bool {
                $moves++;
                return $moves === 1 ? @rename($from, $to) : false;
            }
        );
        $this->app->instance(
            \App\Services\PhysicalPublicSiteDeployer::class,
            new \App\Services\PhysicalPublicSiteDeployer($filesystem),
        );

        $this->expectFailure(fn () => $this->activate($publication, $archive, $sha), 'operator recovery');

        $state = json_decode((string) file_get_contents(
            $this->static.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::STATE_FILE
        ), true, flags: JSON_THROW_ON_ERROR);
        $backup = dirname($this->public).DIRECTORY_SEPARATOR.'.pianissimo-httpdocs-previous';
        $this->assertSame(3, $moves);
        $this->assertSame('ambiguous', $state['status']);
        $this->assertDirectoryDoesNotExist($this->public);
        $this->assertSame('previous-public-site', file_get_contents($backup.DIRECTORY_SEPARATOR.'index.html'));
    }

    public function test_lifecycle_failure_restores_previous_pointer_and_physical_public_site(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$first, $firstArchive, $firstSha] = $this->release(1);
        $this->activate($first, $firstArchive, $firstSha);
        $previous = readlink($this->static.DIRECTORY_SEPARATOR.'current');
        [$second, $secondArchive, $secondSha] = $this->release(2);

        $lifecycle = Mockery::mock(ProductionPublicationService::class)->makePartial();
        $lifecycle->shouldReceive('markActive')->once()->andThrow(new RuntimeException('simulated lifecycle failure'));
        $this->app->instance(ProductionPublicationService::class, $lifecycle);

        $this->expectFailure(fn () => $this->activate($second, $secondArchive, $secondSha), 'simulated lifecycle failure');
        $this->assertSame($previous, readlink($this->static.DIRECTORY_SEPARATOR.'current'));
        $this->assertSame('release-media-1', file_get_contents($this->public.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'release.jpg'));
        $this->assertSame($first->getKey(), ProductionPublication::currentActive()?->getKey());
    }

    public function test_newer_sequence_blocks_old_activation_and_explicit_rollback_preserves_draft_data(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux symlink semantics are verified in the backend runtime.');
        }
        [$old, $oldArchive, $oldSha] = $this->release(1);
        [$new, $newArchive, $newSha] = $this->release(2);
        $this->activate($new, $newArchive, $newSha);
        $this->expectFailure(fn () => $this->activate($old, $oldArchive, $oldSha), 'older production sequence');

        // Retain a valid earlier release by activating it in sequence order in a fresh state.
        @unlink($this->static.DIRECTORY_SEPARATOR.'current');
        File::delete($this->static.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::STATE_FILE);
        File::deleteDirectory($this->static.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'2-'.$new->publication_id);
        ProductionPublication::query()->update(['active_slot' => null]);
        $new->refresh()->forceFill(['status' => ProductionPublicationStatus::Superseded])->saveQuietly();
        $old->refresh()->forceFill(['status' => ProductionPublicationStatus::Built])->saveQuietly();
        $this->activate($old, $oldArchive, $oldSha);
        $new->refresh()->forceFill(['status' => ProductionPublicationStatus::Built])->saveQuietly();
        $this->activate($new, $newArchive, $newSha);

        Setting::put('task-five-draft', ['value' => 'newer draft remains']);
        $result = app(StaticReleaseActivator::class)->rollback($old->publication_id);
        $this->assertSame('rolled_back', $result['result']);
        $this->assertSame(['value' => 'newer draft remains'], Setting::read('task-five-draft'));
        $this->assertSame(ProductionPublicationStatus::Active, $old->refresh()->status);
        $this->assertSame(ProductionPublicationStatus::RolledBack, $new->refresh()->status);
        $this->assertSame(1, ProductionPublication::query()->where('status', ProductionPublicationStatus::Active->value)->count());
        $this->expectFailure(fn () => app(StaticReleaseActivator::class)->rollback('../arbitrary/path'), 'UUID');
    }

    private function release(
        int $sequence,
        ?callable $mutator = null,
        ?string $declaredSha = null,
        bool $trustMutatedMetadata = false,
        bool $resignMutatedFiles = false,
    ): array
    {
        $publicationId = (string) Str::uuid();
        $publication = ProductionPublication::query()->create([
            'publication_id' => $publicationId,
            'sequence' => $sequence,
            'candidate_hash' => hash('sha256', 'candidate-'.$sequence),
            'plan_hash' => hash('sha256', 'plan-'.$sequence),
            'source_revision' => sha1('revision-'.$sequence),
            'package_sha256' => hash('sha256', 'package-'.$sequence),
            'manifest_sha256' => hash('sha256', 'manifest-'.$sequence),
            'package_path' => 'production/'.str_pad((string) $sequence, 12, '0', STR_PAD_LEFT).'-'.$publicationId,
            'status' => ProductionPublicationStatus::Preparing,
            'active_slot' => null,
        ]);
        $lifecycle = app(ProductionPublicationService::class);
        $publication = $lifecycle->markPrepared($publication);
        $publication = $lifecycle->claimForRunner($publication, 'runner-test')['publication'];
        $publication = $lifecycle->ensureDispatchedForClaimedRunner($publication, 'runner-test');
        $publication = $lifecycle->markBuilding($publication);

        $media = 'release-media-'.$sequence;
        $publicEntries = [
            ['index.html', '<!doctype html><script src="/_astro/app.js"></script><link rel="stylesheet" href="/_astro/app.css"><img src="/media/release.jpg">', '0'],
            ['_astro/app.js', 'console.log("static")', '0'],
            ['_astro/app.css', 'body{color:#111}', '0'],
            ['favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', '0'],
            ['robots.txt', "User-agent: *\nDisallow:\n", '0'],
            ['media/release.jpg', $media, '0'],
        ];
        $inventory = array_map(static fn (array $entry): array => [
            'path' => $entry[0],
            'sha256' => hash('sha256', $entry[1]),
            'size' => strlen($entry[1]),
        ], $publicEntries);
        usort($inventory, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $inventoryBytes = json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $releaseFilesSha = hash('sha256', $inventoryBytes);
        $metadata = [
            'releaseContractVersion' => 1,
            'publicationId' => $publicationId,
            'sequence' => $sequence,
            'sourceRevision' => $publication->source_revision,
            'packageSha256' => $publication->package_sha256,
            'manifestSha256' => $publication->manifest_sha256,
            'releaseFilesSha256' => $releaseFilesSha,
            'media' => [[
                'publicPath' => 'media/release.jpg',
                'sha256' => hash('sha256', $media),
                'size' => strlen($media),
                'type' => 'image',
            ]],
        ];
        $metadataBytes = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $entries = [
            ...$publicEntries,
            [StaticReleaseArchive::FILES_FILE, $inventoryBytes, '0'],
            [StaticReleaseArchive::METADATA_FILE, $metadataBytes, '0'],
        ];
        if ($mutator !== null) {
            $entries = $mutator($entries);
        }
        if ($resignMutatedFiles) {
            $publicEntries = array_values(array_filter($entries, static fn (array $entry): bool => ! in_array($entry[0], [StaticReleaseArchive::FILES_FILE, StaticReleaseArchive::METADATA_FILE], true)));
            $inventory = array_map(static fn (array $entry): array => ['path'=>$entry[0],'sha256'=>hash('sha256',$entry[1]),'size'=>strlen($entry[1])],$publicEntries);
            usort($inventory, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
            $inventoryBytes=json_encode($inventory,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            $releaseFilesSha=hash('sha256',$inventoryBytes);
            foreach($entries as &$entry){
                if($entry[0]===StaticReleaseArchive::FILES_FILE)$entry[1]=$inventoryBytes;
                if($entry[0]===StaticReleaseArchive::METADATA_FILE){$resigned=json_decode($entry[1],true,flags:JSON_THROW_ON_ERROR);$resigned['releaseFilesSha256']=$releaseFilesSha;$entry[1]=json_encode($resigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";}
            }
            unset($entry);
        }
        $recordedMetadataBytes = $metadataBytes;
        if ($trustMutatedMetadata) {
            foreach ($entries as $entry) {
                if ($entry[0] === StaticReleaseArchive::METADATA_FILE) {
                    $recordedMetadataBytes = $entry[1];
                    break;
                }
            }
        }
        $bytes = $this->tar($entries);
        $actualSha = hash('sha256', $bytes);
        $sha = $declaredSha ?? $actualSha;
        $filename = $this->filename($sequence, $publicationId, $sha);
        file_put_contents($this->incoming.DIRECTORY_SEPARATOR.$filename, $bytes);
        $publication = $lifecycle->markBuilt($publication, $sha, $releaseFilesSha, hash('sha256', $recordedMetadataBytes));

        return [$publication, $filename, $actualSha];
    }

    private function activate(ProductionPublication $publication, string $archive, string $sha): array
    {
        return app(StaticReleaseActivator::class)->activate($publication->publication_id, $publication->sequence, $archive, $sha, 'runner-test');
    }

    private function filename(int $sequence, string $publicationId, string $sha): string
    {
        return "pianissimo-static-release-{$sequence}-{$publicationId}-{$sha}.tar";
    }

    private function tar(array $entries): string
    {
        $archive = '';
        foreach ($entries as [$name, $data, $type]) {
            $header = str_repeat("\0", 512);
            $this->putHeader($header, 0, 100, $name);
            $this->putHeader($header, 100, 8, $this->octal(0644, 8));
            $this->putHeader($header, 108, 8, $this->octal(0, 8));
            $this->putHeader($header, 116, 8, $this->octal(0, 8));
            $this->putHeader($header, 124, 12, $this->octal(strlen($data), 12));
            $this->putHeader($header, 136, 12, $this->octal(0, 12));
            $this->putHeader($header, 148, 8, str_repeat(' ', 8));
            $this->putHeader($header, 156, 1, $type);
            $this->putHeader($header, 257, 6, "ustar\0");
            $this->putHeader($header, 263, 2, '00');
            $checksum = array_sum(unpack('C*', $header));
            $this->putHeader($header, 148, 8, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ");
            $archive .= $header.$data.str_repeat("\0", (512 - (strlen($data) % 512)) % 512);
        }

        return $archive.str_repeat("\0", 1024);
    }

    private function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";
    }

    private function putHeader(string &$target, int $offset, int $length, string $value): void
    {
        $target = substr_replace($target, str_pad(substr($value, 0, $length), $length, "\0"), $offset, $length);
    }

    private function expectFailure(callable $operation, ?string $message = null): void
    {
        try {
            $operation();
            $this->fail('Expected production release operation to fail closed.');
        } catch (DomainException|RuntimeException $exception) {
            if ($message !== null) {
                $this->assertStringContainsStringIgnoringCase($message, $exception->getMessage());
            } else {
                $this->assertTrue(true);
            }
        }
    }
}
