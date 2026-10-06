<?php

namespace App\Services;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class StaticReleaseActivator
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function __construct(
        private readonly StaticReleaseFilesystem $filesystem,
        private readonly StaticReleaseArchive $archive,
        private readonly ProductionPublicationService $lifecycle,
        private readonly PhysicalPublicSiteDeployer $publicSite,
    ) {}

    public function activate(string $publicationId, int|string $sequence, string $archiveFilename, string $expectedSha256, string $runnerId): array
    {
        $identity = $this->validateActivationInput($publicationId, $sequence, $archiveFilename, $expectedSha256, $runnerId);
        [$incomingRoot, $staticRoot] = $this->validatedRoots();
        $archivePath = $incomingRoot.DIRECTORY_SEPARATOR.$archiveFilename;
        if (! is_file($archivePath) || is_link($archivePath)) {
            throw new DomainException('Transferred production release archive is unavailable.');
        }
        $publication = ProductionPublication::query()->where('publication_id', $publicationId)->firstOrFail();
        $this->validatePublication($publication, $identity['sequence'], $expectedSha256, $runnerId, true);

        $lock = $this->filesystem->lock($staticRoot);
        try {
            $publication->refresh();
            $this->validatePublication($publication, $identity['sequence'], $expectedSha256, $runnerId, true);
            $releaseName = $identity['sequence'].'-'.$publicationId;
            $target = 'releases/'.$releaseName;
            $releaseDirectory = $staticRoot.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$releaseName;
            $state = $this->filesystem->readState($staticRoot);
            if (($state['status'] ?? null) !== 'stable') {
                throw new DomainException('Production activation state is ambiguous and requires operator recovery.');
            }
            $current = $this->filesystem->currentTarget($staticRoot);
            $this->assertStateMatchesCurrent($state, $current);
            $highest = max((int) $state['highestActivatedSequence'], $this->sequenceFromTarget($current), $this->highestRetainedSequence($staticRoot, $releaseName));
            if ($current !== $target && $identity['sequence'] <= $highest) {
                throw new DomainException('An older production sequence requires explicit rollback.');
            }

            if (is_dir($releaseDirectory) || file_exists($releaseDirectory) || is_link($releaseDirectory)) {
                $this->validateRetainedRelease($releaseDirectory, $publication, $expectedSha256);
            } else {
                $this->stageRelease($archivePath, $releaseDirectory, $publication, $expectedSha256, $staticRoot);
            }
            $publicDeployment = $this->publicSite->prepare($releaseDirectory, $publication);

            if ($current === $target && $publication->status === ProductionPublicationStatus::Active) {
                try {
                    $this->publicSite->activate($publicDeployment);
                } catch (AmbiguousPublicDeploymentException $failure) {
                    $this->recordCurrentPublicStateAmbiguous($staticRoot, $state);
                    throw new RuntimeException('Public website activation is ambiguous; production state requires operator recovery.', previous: $failure);
                }
                return ['publication' => $publication, 'release' => $target, 'result' => 'already_active'];
            }

            $previousState = $state;
            try {
                $previous = $this->filesystem->switchCurrent($staticRoot, $target);
            } catch (Throwable $failure) {
                $this->publicSite->abort($publicDeployment);
                throw $failure;
            }
            $nextState = [
                'version' => 1,
                'highestActivatedSequence' => max($highest, $identity['sequence']),
                'activeRelease' => $target,
                'previousRelease' => $previous,
                'status' => 'stable',
            ];
            try {
                $this->filesystem->writeState($staticRoot, $nextState);
                $publicDeployment = $this->publicSite->activate($publicDeployment);
                $publication = $this->finishActivationLifecycle($publication);
            } catch (Throwable $failure) {
                if ($failure instanceof AmbiguousPublicDeploymentException) {
                    $this->compensatePointer($staticRoot, $previous, $previousState, $target);
                    $this->recordAmbiguousPublicState($staticRoot, $previousState, $previous, $target);
                    throw new RuntimeException('Public website activation is ambiguous; production state requires operator recovery.', previous: $failure);
                }
                try {
                    $this->publicSite->rollback($publicDeployment);
                } catch (Throwable) {
                    $this->compensatePointer($staticRoot, $previous, $previousState, $target);
                    $this->recordAmbiguousPublicState($staticRoot, $previousState, $previous, $target);
                    throw new RuntimeException('Public website rollback failed; production state requires operator recovery.');
                }
                $this->compensatePointer($staticRoot, $previous, $previousState, $target);
                throw $failure;
            }

            return ['publication' => $publication, 'release' => $target, 'result' => 'activated'];
        } finally {
            $this->filesystem->unlock($lock);
        }
    }

    public function rollback(string $releaseOrPublication): array
    {
        if (preg_match('/^'.self::UUID.'$/D', $releaseOrPublication) === 1) {
            $publication = ProductionPublication::query()->where('publication_id', $releaseOrPublication)->firstOrFail();
        } elseif (preg_match('/^([1-9][0-9]*)-('.self::UUID.')$/D', $releaseOrPublication, $matches) === 1) {
            $publication = ProductionPublication::query()
                ->where('sequence', (int) $matches[1])
                ->where('publication_id', $matches[2])
                ->firstOrFail();
        } else {
            throw new DomainException('Rollback target must be a publication UUID or retained release name.');
        }

        [, $staticRoot] = $this->validatedRoots();
        $target = 'releases/'.$publication->sequence.'-'.$publication->publication_id;
        $releaseDirectory = $staticRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $target);
        $lock = $this->filesystem->lock($staticRoot);
        try {
            $state = $this->filesystem->readState($staticRoot);
            if (($state['status'] ?? null) !== 'stable') {
                throw new DomainException('Production activation state is ambiguous and requires operator recovery.');
            }
            $this->validateRetainedRelease($releaseDirectory, $publication, (string) $publication->release_sha256);
            $publicDeployment = $this->publicSite->prepare($releaseDirectory, $publication);
            $current = $this->filesystem->currentTarget($staticRoot);
            $this->assertStateMatchesCurrent($state, $current);
            if ($current === $target && $publication->status === ProductionPublicationStatus::Active) {
                try {
                    $this->publicSite->activate($publicDeployment);
                } catch (AmbiguousPublicDeploymentException $failure) {
                    $this->recordCurrentPublicStateAmbiguous($staticRoot, $state);
                    throw new RuntimeException('Public website activation is ambiguous; production state requires operator recovery.', previous: $failure);
                }
                return ['publication' => $publication, 'release' => $target, 'result' => 'already_active'];
            }
            if (! in_array($publication->status, [ProductionPublicationStatus::Superseded, ProductionPublicationStatus::RolledBack], true)) {
                throw new DomainException('Rollback target is not a previously active retained publication.');
            }
            if ($current === null) {
                throw new DomainException('Rollback requires an existing active production release.');
            }
            $previousState = $state;
            try {
                $previous = $this->filesystem->switchCurrent($staticRoot, $target);
            } catch (Throwable $failure) {
                $this->publicSite->abort($publicDeployment);
                throw $failure;
            }
            $nextState = [
                'version' => 1,
                'highestActivatedSequence' => max((int) $state['highestActivatedSequence'], $this->sequenceFromTarget($current), (int) $publication->sequence),
                'activeRelease' => $target,
                'previousRelease' => $previous,
                'status' => 'stable',
            ];
            try {
                $this->filesystem->writeState($staticRoot, $nextState);
                $publicDeployment = $this->publicSite->activate($publicDeployment);
                $publication = $this->lifecycle->activateRollback($publication);
            } catch (Throwable $failure) {
                if ($failure instanceof AmbiguousPublicDeploymentException) {
                    $this->compensatePointer($staticRoot, $previous, $previousState, $target);
                    $this->recordAmbiguousPublicState($staticRoot, $previousState, $previous, $target);
                    throw new RuntimeException('Public website activation is ambiguous; production state requires operator recovery.', previous: $failure);
                }
                try {
                    $this->publicSite->rollback($publicDeployment);
                } catch (Throwable) {
                    $this->compensatePointer($staticRoot, $previous, $previousState, $target);
                    $this->recordAmbiguousPublicState($staticRoot, $previousState, $previous, $target);
                    throw new RuntimeException('Public website rollback failed; production state requires operator recovery.');
                }
                $this->compensatePointer($staticRoot, $previous, $previousState, $target);
                throw $failure;
            }

            return ['publication' => $publication, 'release' => $target, 'result' => 'rolled_back'];
        } finally {
            $this->filesystem->unlock($lock);
        }
    }

    private function validateActivationInput(string $publicationId, int|string $sequence, string $archiveFilename, string $sha256, string $runnerId): array
    {
        if (preg_match('/^'.self::UUID.'$/D', $publicationId) !== 1
            || preg_match('/^[1-9][0-9]*$/D', (string) $sequence) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $runnerId) !== 1) {
            throw new DomainException('Invalid production activation identity.');
        }
        $pattern = '/^pianissimo-static-release-([1-9][0-9]*)-('.self::UUID.')-([0-9a-f]{64})\.tar$/D';
        if (preg_match($pattern, $archiveFilename, $matches) !== 1
            || (int) $matches[1] !== (int) $sequence
            || $matches[2] !== $publicationId
            || ! hash_equals($matches[3], $sha256)) {
            throw new DomainException('Production release archive filename does not match its identity.');
        }

        return ['sequence' => (int) $sequence];
    }

    private function validatePublication(ProductionPublication $publication, int $sequence, string $sha256, string $runnerId, bool $allowActive = false): void
    {
        if ((int) $publication->sequence !== $sequence
            || ! is_string($publication->release_sha256)
            || ! hash_equals($publication->release_sha256, $sha256)) {
            throw new DomainException('Production release identity does not match the CMS publication.');
        }
        $this->lifecycle->assertClaimingRunner($publication, $runnerId);
        $allowed = [ProductionPublicationStatus::Built, ProductionPublicationStatus::Uploading, ProductionPublicationStatus::Uploaded, ProductionPublicationStatus::Activating];
        if ($allowActive) {
            $allowed[] = ProductionPublicationStatus::Active;
        }
        if (! in_array($publication->status, $allowed, true)) {
            throw new DomainException('Production publication is not ready for activation.');
        }
    }

    private function stageRelease(string $archivePath, string $releaseDirectory, ProductionPublication $publication, string $sha256, string $staticRoot): void
    {
        $stagingRoot = $staticRoot.DIRECTORY_SEPARATOR.'.staging';
        File::ensureDirectoryExists($stagingRoot, 0750);
        $stage = $stagingRoot.DIRECTORY_SEPARATOR.$publication->sequence.'-'.$publication->publication_id.'-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($stage, 0750);
        $handle = @fopen($archivePath, 'rb');
        if ($handle === false || ! @flock($handle, LOCK_SH)) {
            File::deleteDirectory($stage);
            throw new RuntimeException('Transferred production release archive could not be locked.');
        }
        try {
            $stat = fstat($handle);
            if (! is_array($stat) || ($stat['size'] ?? 0) < 1 || ($stat['size'] ?? 0) > 2147483648) {
                throw new DomainException('Transferred production release archive size is unsafe.');
            }
            $context = hash_init('sha256');
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Transferred production release archive could not be read.');
                }
                hash_update($context, $chunk);
            }
            if (! hash_equals($sha256, hash_final($context))) {
                throw new DomainException('Transferred production release archive SHA-256 does not match.');
            }
            $entries = $this->archive->inspect($handle);
            $this->archive->extract($handle, $entries, $stage);
            $expected = $this->expectedMetadata($publication);
            $this->archive->validateExtracted($stage, $expected);
            $digestBytes = $sha256."\n";
            if (file_put_contents($stage.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE, $digestBytes, LOCK_EX) !== strlen($digestBytes)) {
                throw new RuntimeException('Production release digest marker could not be written.');
            }
            $this->archive->validateExtracted($stage, $expected);
            $this->filesystem->renameDirectory($stage, $releaseDirectory);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            if (is_dir($stage)) {
                File::deleteDirectory($stage);
            }
        }
    }

    private function validateRetainedRelease(string $releaseDirectory, ProductionPublication $publication, string $sha256): void
    {
        if (! is_dir($releaseDirectory) || is_link($releaseDirectory) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new DomainException('Retained production release is unavailable or unsafe.');
        }
        $digestPath = $releaseDirectory.DIRECTORY_SEPARATOR.StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE;
        if (! is_file($digestPath) || is_link($digestPath) || ! hash_equals($sha256, trim((string) file_get_contents($digestPath)))) {
            throw new DomainException('Existing final production release has a different archive SHA-256.');
        }
        $this->archive->validateExtracted($releaseDirectory, $this->expectedMetadata($publication));
    }

    private function finishActivationLifecycle(ProductionPublication $publication): ProductionPublication
    {
        $publication->refresh();
        if ($publication->status === ProductionPublicationStatus::Built) {
            $publication = $this->lifecycle->markUploading($publication);
        }
        if ($publication->status === ProductionPublicationStatus::Uploading) {
            $publication = $this->lifecycle->markUploaded($publication);
        }
        if ($publication->status === ProductionPublicationStatus::Uploaded) {
            $publication = $this->lifecycle->markActivating($publication);
        }
        if ($publication->status === ProductionPublicationStatus::Activating) {
            $publication = $this->lifecycle->markActive($publication);
        }
        if ($publication->status !== ProductionPublicationStatus::Active) {
            throw new DomainException('Production publication did not reach active state.');
        }

        return $publication;
    }

    private function validatedRoots(): array
    {
        if (config('cms.publication_driver') !== 'package') {
            throw new DomainException('Production package publication driver is not configured.');
        }
        $incoming = $this->validatedMarkedRoot((string) config('cms.production_incoming_root'), StaticReleaseFilesystem::INCOMING_MARKER, StaticReleaseFilesystem::INCOMING_MARKER_CONTENT);
        $static = $this->validatedMarkedRoot((string) config('cms.static_release_root'), StaticReleaseFilesystem::ROOT_MARKER, StaticReleaseFilesystem::ROOT_MARKER_CONTENT);
        if ($incoming === $static || str_starts_with($incoming.DIRECTORY_SEPARATOR, $static.DIRECTORY_SEPARATOR) || str_starts_with($static.DIRECTORY_SEPARATOR, $incoming.DIRECTORY_SEPARATOR)) {
            throw new DomainException('Production incoming and static release roots must be distinct.');
        }
        $releases = $static.DIRECTORY_SEPARATOR.'releases';
        if (is_link($releases) || (! is_dir($releases) && ! @mkdir($releases, 0750, true) && ! is_dir($releases))) {
            throw new DomainException('Production releases directory is unavailable or unsafe.');
        }

        return [$incoming, $static];
    }

    private function validatedMarkedRoot(string $configured, string $marker, string $content): string
    {
        if ($configured === '' || str_contains($configured, "\0") || preg_match('#(?:^|[\\/])\.\.?([\\/]|$)#', $configured)
            || (preg_match('#^/#', $configured) !== 1 && preg_match('#^[A-Za-z]:[\\/]#', $configured) !== 1)) {
            throw new DomainException('Production release root configuration is unsafe.');
        }
        $root = realpath($configured);
        if ($root === false || ! is_dir($root) || is_link($configured)) {
            throw new DomainException('Production release root does not exist safely.');
        }
        $markerPath = $root.DIRECTORY_SEPARATOR.$marker;
        $markerBytes = is_file($markerPath) && ! is_link($markerPath) ? file_get_contents($markerPath) : false;
        if (! is_string($markerBytes) || ! in_array($markerBytes, [$content, $content."\n", $content."\r\n"], true)) {
            throw new DomainException('Production release root safety marker is missing or incorrect.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function expectedMetadata(ProductionPublication $publication): array
    {
        return [
            'publicationId' => $publication->publication_id,
            'sequence' => (int) $publication->sequence,
            'sourceRevision' => $publication->source_revision,
            'packageSha256' => $publication->package_sha256,
            'manifestSha256' => $publication->manifest_sha256,
            'releaseFilesSha256' => $publication->release_files_sha256,
            'releaseMetadataSha256' => $publication->release_metadata_sha256,
        ];
    }

    private function sequenceFromTarget(?string $target): int
    {
        return is_string($target) && preg_match('#^releases/([1-9][0-9]*)-#', $target, $matches) === 1 ? (int) $matches[1] : 0;
    }

    private function highestRetainedSequence(string $staticRoot, string $exceptRelease): int
    {
        $highest = 0;
        $entries = scandir($staticRoot.DIRECTORY_SEPARATOR.'releases');
        if ($entries === false) {
            throw new RuntimeException('Retained production releases could not be inspected.');
        }
        foreach ($entries as $entry) {
            if ($entry === $exceptRelease) {
                continue;
            }
            if (preg_match('/^([1-9][0-9]*)-'.self::UUID.'$/D', $entry, $matches) === 1) {
                $path = $staticRoot.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$entry;
                if (is_link($path) || ! is_dir($path)) {
                    throw new DomainException('Retained production release entry is unsafe.');
                }
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }

    private function assertStateMatchesCurrent(array $state, ?string $current): void
    {
        $recorded = $state['activeRelease'] ?? null;
        if ($recorded !== $current) {
            throw new DomainException('Production activation state and current pointer disagree.');
        }
        if ($recorded === null
            && ((int) ($state['highestActivatedSequence'] ?? -1) !== 0 || ($state['previousRelease'] ?? null) !== null)) {
            throw new DomainException('Production activation state without a current pointer is not pristine.');
        }
    }

    private function compensatePointer(string $staticRoot, ?string $previous, array $previousState, string $attempted): void
    {
        try {
            $this->filesystem->restoreCurrent($staticRoot, $previous);
            $this->filesystem->writeState($staticRoot, $previousState);
        } catch (Throwable) {
            try {
                $this->filesystem->writeState($staticRoot, [
                    'version' => 1,
                    'highestActivatedSequence' => max((int) ($previousState['highestActivatedSequence'] ?? 0), $this->sequenceFromTarget($attempted)),
                    'activeRelease' => $attempted,
                    'previousRelease' => $previous,
                    'status' => 'ambiguous',
                ]);
            } catch (Throwable) {
                // The command fails closed; operators must inspect current and both markers.
            }
            throw new RuntimeException('Production activation compensation failed; state is ambiguous.');
        }
    }

    private function recordAmbiguousPublicState(string $staticRoot, array $previousState, ?string $restored, string $attempted): void
    {
        try {
            $this->filesystem->writeState($staticRoot, [
                'version' => 1,
                'highestActivatedSequence' => max((int) ($previousState['highestActivatedSequence'] ?? 0), $this->sequenceFromTarget($attempted)),
                'activeRelease' => $restored,
                'previousRelease' => $attempted,
                'status' => 'ambiguous',
            ]);
        } catch (Throwable) {
            // The activation command already fails closed; operators must inspect both physical roots.
        }
    }

    private function recordCurrentPublicStateAmbiguous(string $staticRoot, array $state): void
    {
        try {
            $state['status'] = 'ambiguous';
            $this->filesystem->writeState($staticRoot, $state);
        } catch (Throwable) {
            // The activation command already fails closed; operators must inspect both physical roots.
        }
    }
}
