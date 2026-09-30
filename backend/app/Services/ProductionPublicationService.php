<?php

namespace App\Services;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductionPublicationService
{
    public function claimForRunner(ProductionPublication $publication, string $runnerId): array
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $runnerId)) {
            throw new DomainException('Invalid publisher runner identifier.');
        }

        return DB::transaction(function () use ($publication, $runnerId): array {
            $locked = ProductionPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            if ($locked->claimed_by !== null) {
                if (! in_array($locked->status, $this->resumablePublisherStatuses(), true)) {
                    throw new DomainException('Production publication is not claimable.');
                }
                if (! hash_equals((string) $locked->claimed_by, $runnerId)) {
                    throw new DomainException('Production publication is already claimed.');
                }

                return ['publication' => $locked->refresh(), 'result' => 'already_claimed'];
            }
            if (! in_array($locked->status, [
                ProductionPublicationStatus::Prepared,
                ProductionPublicationStatus::Dispatching,
                ProductionPublicationStatus::Dispatched,
            ], true)) {
                throw new DomainException('Production publication is not claimable.');
            }

            $locked->claimed_by = $runnerId;
            $locked->claimed_at = now();
            $locked->save();
            $this->audit($locked, 'claimed');

            return ['publication' => $locked->refresh(), 'result' => 'claimed'];
        }, 3);
    }

    public function resumeClaimedWorkflow(ProductionPublication $publication, string $runnerId): array
    {
        return DB::transaction(function () use ($publication, $runnerId): array {
            $locked = ProductionPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            $this->assertClaimingRunner($locked, $runnerId);

            if ($locked->status === ProductionPublicationStatus::Prepared) {
                $locked->status = ProductionPublicationStatus::Dispatching;
                $locked->save();
                $this->audit($locked, ProductionPublicationStatus::Dispatching->value);
            }
            if ($locked->status === ProductionPublicationStatus::Dispatching) {
                $locked->status = ProductionPublicationStatus::Dispatched;
                $locked->dispatched_at = now();
                $locked->save();
                $this->audit($locked, ProductionPublicationStatus::Dispatched->value);
            }
            if ($locked->status === ProductionPublicationStatus::Failed) {
                $hasReleaseIdentity = is_string($locked->release_sha256)
                    && is_string($locked->release_files_sha256)
                    && is_string($locked->release_metadata_sha256);
                $locked->status = $hasReleaseIdentity
                    ? ProductionPublicationStatus::Built
                    : ProductionPublicationStatus::Dispatched;
                $locked->failure_code = null;
                $locked->failure_message = null;
                $locked->save();
                $this->audit($locked, 'publisher-retry-'.$locked->status->value);
            }
            if (! in_array($locked->status, $this->resumablePublisherStatuses(), true)) {
                throw new DomainException('Claimed production publication cannot resume the publisher workflow.');
            }

            $status = $locked->status;
            $buildRequired = $this->requiresBuildForResume($status);
            $uploadRequired = $buildRequired;

            return [
                'publication' => $locked->refresh(),
                'resume' => [
                    'state' => $status->value,
                    'build_required' => $buildRequired,
                    'upload_required' => $uploadRequired,
                    'activation_required' => $status !== ProductionPublicationStatus::Active,
                    'notify_building' => $status === ProductionPublicationStatus::Dispatched,
                    'notify_built' => in_array($status, [ProductionPublicationStatus::Dispatched, ProductionPublicationStatus::Building], true),
                    'notify_uploading' => in_array($status, [ProductionPublicationStatus::Dispatched, ProductionPublicationStatus::Building, ProductionPublicationStatus::Built], true),
                ],
            ];
        }, 3);
    }

    public function ensureDispatchedForClaimedRunner(ProductionPublication $publication, string $runnerId): ProductionPublication
    {
        return DB::transaction(function () use ($publication, $runnerId): ProductionPublication {
            $locked = ProductionPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            $this->assertClaimingRunner($locked, $runnerId);
            if ($locked->status === ProductionPublicationStatus::Prepared) {
                $locked->status = ProductionPublicationStatus::Dispatching;
                $locked->save();
                $this->audit($locked, ProductionPublicationStatus::Dispatching->value);
            }
            if ($locked->status === ProductionPublicationStatus::Dispatching) {
                $locked->status = ProductionPublicationStatus::Dispatched;
                $locked->dispatched_at = now();
                $locked->save();
                $this->audit($locked, ProductionPublicationStatus::Dispatched->value);
            }
            if (! in_array($locked->status, [
                ProductionPublicationStatus::Dispatched,
                ProductionPublicationStatus::Building,
                ProductionPublicationStatus::Built,
            ], true)) {
                throw new DomainException('Claimed production publication cannot enter the build workflow.');
            }

            return $locked->refresh();
        }, 3);
    }

    public function markPrepared(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Prepared);
    }

    public function markDispatching(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Dispatching);
    }

    public function markDispatched(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Dispatched, ['dispatched_at' => now()]);
    }

    public function markBuilding(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Building);
    }

    public function markBuilt(
        ProductionPublication $publication,
        string $releaseSha256,
        string $releaseFilesSha256,
        string $releaseMetadataSha256,
    ): ProductionPublication
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $releaseSha256)
            || ! preg_match('/^[0-9a-f]{64}$/', $releaseFilesSha256)
            || ! preg_match('/^[0-9a-f]{64}$/', $releaseMetadataSha256)) {
            throw new DomainException('Invalid production release SHA-256.');
        }

        return $this->transition($publication, ProductionPublicationStatus::Built, [
            'release_sha256' => $releaseSha256,
            'release_files_sha256' => $releaseFilesSha256,
            'release_metadata_sha256' => $releaseMetadataSha256,
            'built_at' => now(),
        ]);
    }

    public function updateFromRunner(
        ProductionPublication $publication,
        string $runnerId,
        string $requested,
        ?string $releaseSha256 = null,
        ?string $releaseFilesSha256 = null,
        ?string $releaseMetadataSha256 = null,
        string $failureCode = 'workflow_failed',
        ?string $failureMessage = null,
    ): ProductionPublication {
        $publication->refresh();
        $this->assertClaimingRunner($publication, $runnerId);

        if ($requested === 'building') {
            if ($publication->status === ProductionPublicationStatus::Dispatched) {
                return $this->markBuilding($publication);
            }
            if ($publication->status === ProductionPublicationStatus::Building) {
                return $publication;
            }
        } elseif ($requested === 'built') {
            if ($publication->status === ProductionPublicationStatus::Building) {
                return $this->markBuilt(
                    $publication,
                    (string) $releaseSha256,
                    (string) $releaseFilesSha256,
                    (string) $releaseMetadataSha256,
                );
            }
            if ($publication->status === ProductionPublicationStatus::Built
                && hash_equals((string) $publication->release_sha256, (string) $releaseSha256)
                && hash_equals((string) $publication->release_files_sha256, (string) $releaseFilesSha256)
                && hash_equals((string) $publication->release_metadata_sha256, (string) $releaseMetadataSha256)) {
                return $publication;
            }
        } elseif ($requested === 'uploading') {
            if ($publication->status === ProductionPublicationStatus::Built) {
                return $this->markUploading($publication);
            }
            if ($publication->status === ProductionPublicationStatus::Uploading) {
                return $publication;
            }
        } elseif ($requested === 'uploaded') {
            if ($publication->status === ProductionPublicationStatus::Uploading) {
                return $this->markUploaded($publication);
            }
            if ($publication->status === ProductionPublicationStatus::Uploaded) {
                return $publication;
            }
        } elseif ($requested === 'failed') {
            if ($publication->status === ProductionPublicationStatus::Failed) {
                return $publication;
            }

            return $this->markFailed($publication, $failureCode, $failureMessage);
        }

        throw new DomainException('Illegal publisher status transition.');
    }

    public function markUploaded(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Uploaded);
    }

    public function markUploading(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Uploading);
    }

    public function markActivating(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Activating);
    }

    public function markActive(ProductionPublication $publication): ProductionPublication
    {
        return DB::transaction(function () use ($publication): ProductionPublication {
            $this->lockGlobalState();
            $locked = ProductionPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            if ($locked->status !== ProductionPublicationStatus::Activating) {
                throw new DomainException("Illegal production publication transition: {$locked->status->value} -> active.");
            }

            foreach (ProductionPublication::query()->where('status', ProductionPublicationStatus::Active->value)->lockForUpdate()->get() as $active) {
                $active->status = ProductionPublicationStatus::Superseded;
                $active->active_slot = null;
                $active->save();
                $this->audit($active, 'superseded');
            }

            $locked->status = ProductionPublicationStatus::Active;
            $locked->active_slot = 1;
            $locked->activated_at = now();
            $locked->failure_code = null;
            $locked->failure_message = null;
            $locked->save();
            $this->audit($locked, 'active');

            return $locked->refresh();
        });
    }

    public function markFailed(ProductionPublication $publication, string $failureCode, ?string $failureMessage = null): ProductionPublication
    {
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $failureCode)) {
            throw new DomainException('Invalid production publication failure code.');
        }

        return $this->transition($publication, ProductionPublicationStatus::Failed, [
            'failure_code' => $failureCode,
            // Runner text is intentionally not persisted: even apparently benign
            // diagnostics can contain credentials, paths, stack traces or URLs.
            'failure_message' => $failureMessage === null
                ? 'Production publication failed. See the safe failure code.'
                : 'Publisher reported a sanitized build failure. See the safe failure code.',
        ]);
    }

    public function assertClaimingRunner(ProductionPublication $publication, string $runnerId): void
    {
        if ($publication->claimed_by === null || ! hash_equals((string) $publication->claimed_by, $runnerId)) {
            throw new DomainException('Publisher runner does not own this publication claim.');
        }
    }

    public function requiresBuildForResume(ProductionPublicationStatus $status): bool
    {
        return in_array($status, [
            ProductionPublicationStatus::Dispatched,
            ProductionPublicationStatus::Building,
            ProductionPublicationStatus::Built,
            ProductionPublicationStatus::Uploading,
        ], true);
    }

    public function retryPreparation(ProductionPublication $publication): ProductionPublication
    {
        $publication->refresh();
        if ($publication->status !== ProductionPublicationStatus::Failed || $publication->failure_code !== 'package_preparation_failed') {
            throw new DomainException('Only failed package preparation may be retried.');
        }

        return $this->transition($publication, ProductionPublicationStatus::Preparing, [
            'failure_code' => null,
            'failure_message' => null,
        ]);
    }

    public function markSuperseded(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::Superseded);
    }

    public function markRolledBack(ProductionPublication $publication): ProductionPublication
    {
        return $this->transition($publication, ProductionPublicationStatus::RolledBack);
    }

    public function activateRollback(ProductionPublication $target): ProductionPublication
    {
        return DB::transaction(function () use ($target): ProductionPublication {
            $this->lockGlobalState();
            $lockedTarget = ProductionPublication::query()->lockForUpdate()->findOrFail($target->getKey());
            $active = ProductionPublication::query()
                ->where('active_slot', 1)
                ->where('status', ProductionPublicationStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($active?->getKey() === $lockedTarget->getKey()) {
                return $lockedTarget->refresh();
            }
            if (! in_array($lockedTarget->status, [ProductionPublicationStatus::Superseded, ProductionPublicationStatus::RolledBack], true)) {
                throw new DomainException('Rollback target is not a previously active retained publication.');
            }
            if ($active !== null) {
                $active->status = ProductionPublicationStatus::RolledBack;
                $active->active_slot = null;
                $active->save();
                $this->audit($active, ProductionPublicationStatus::RolledBack->value);
            }
            $lockedTarget->status = ProductionPublicationStatus::Active;
            $lockedTarget->active_slot = 1;
            $lockedTarget->activated_at = now();
            $lockedTarget->failure_code = null;
            $lockedTarget->failure_message = null;
            $lockedTarget->save();
            $this->audit($lockedTarget, 'active-rollback');

            return $lockedTarget->refresh();
        }, 3);
    }

    private function transition(ProductionPublication $publication, ProductionPublicationStatus $to, array $attributes = []): ProductionPublication
    {
        return DB::transaction(function () use ($publication, $to, $attributes): ProductionPublication {
            $locked = ProductionPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            if (! ProductionPublication::canTransition($locked->status, $to)) {
                throw new DomainException("Illegal production publication transition: {$locked->status->value} -> {$to->value}.");
            }
            $locked->fill($attributes);
            $locked->status = $to;
            if ($locked->getRawOriginal('status') === ProductionPublicationStatus::Active->value && $to !== ProductionPublicationStatus::Active) {
                $locked->active_slot = null;
            }
            if ($to !== ProductionPublicationStatus::Failed) {
                $locked->failure_code = $attributes['failure_code'] ?? null;
                $locked->failure_message = $attributes['failure_message'] ?? null;
            }
            $locked->save();
            $this->audit($locked, $to->value);

            return $locked->refresh();
        });
    }

    private function lockGlobalState(): void
    {
        DB::table('production_publication_counters')->where('id', 1)->lockForUpdate()->first();
    }

    private function resumablePublisherStatuses(): array
    {
        return [
            ProductionPublicationStatus::Prepared,
            ProductionPublicationStatus::Dispatching,
            ProductionPublicationStatus::Dispatched,
            ProductionPublicationStatus::Building,
            ProductionPublicationStatus::Built,
            ProductionPublicationStatus::Uploading,
            ProductionPublicationStatus::Uploaded,
            ProductionPublicationStatus::Activating,
            ProductionPublicationStatus::Active,
            ProductionPublicationStatus::Failed,
        ];
    }

    private function audit(ProductionPublication $publication, string $status): void
    {
        app(AuditLogger::class)->record(
            'production-publication',
            $publication->publication_id,
            'status-'.$status,
            $publication->package_sha256,
            ['sequence' => $publication->sequence, 'status' => $status],
        );
    }
}
