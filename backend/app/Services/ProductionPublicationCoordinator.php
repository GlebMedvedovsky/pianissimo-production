<?php

namespace App\Services;

use App\Enums\ProductionPublicationStatus;
use App\Models\AuditEvent;
use App\Models\ProductionPublication;
use DomainException;
use Throwable;

final class ProductionPublicationCoordinator
{
    public function __construct(
        private readonly ContentValidator $validator,
        private readonly ProductionPublicationPackager $packager,
        private readonly ProductionPublicationService $lifecycle,
        private readonly ProductionWorkflowDispatcher $dispatcher,
    ) {}

    public function publish(int $userId): ProductionPublication
    {
        if ($userId < 1) throw new DomainException('Die Production-Veröffentlichung benötigt einen angemeldeten Administrator.');
        if (config('publisher.production_automation_enabled') !== true) {
            throw new DomainException('Die automatische Production-Veröffentlichung ist noch nicht freigeschaltet.');
        }
        $accepted = $this->acceptedState(true);
        $publication = $this->packager->prepare([
            'manifest' => $accepted['manifest'],
            'candidateHash' => $accepted['candidateHash'],
            'planHash' => $accepted['planHash'],
            'sourceRevision' => $accepted['sourceRevision'],
            'media' => $accepted['media'],
        ], $userId);

        $dispatch = $this->lifecycle->beginDispatch($publication);
        $publication = $dispatch['publication'];
        if ($dispatch['dispatch'] !== true) return $publication;
        try {
            $this->dispatcher->dispatch($publication);
        } catch (Throwable $exception) {
            $publication->refresh();
            if ($publication->status === ProductionPublicationStatus::Dispatching) {
                $this->lifecycle->markFailed($publication, 'workflow_dispatch_failed');
                throw $exception;
            }
            if (! in_array($publication->status, [
                ProductionPublicationStatus::Dispatched,
                ProductionPublicationStatus::Building,
                ProductionPublicationStatus::Built,
                ProductionPublicationStatus::Uploading,
                ProductionPublicationStatus::Uploaded,
                ProductionPublicationStatus::Activating,
                ProductionPublicationStatus::Active,
            ], true)) {
                throw $exception;
            }
        }

        return $publication->refresh();
    }

    public function status(): array
    {
        if (config('publisher.production_automation_enabled') !== true) {
            return ['state' => 'not_configured', 'ready' => false, 'message' => 'Die automatische öffentliche Veröffentlichung ist noch nicht freigeschaltet.'];
        }
        try {
            $accepted = $this->acceptedState(false);
        } catch (Throwable) {
            return ['state' => 'not_ready', 'ready' => false, 'message' => 'Noch kein geprüfter und übernommener Website-Stand ist zur Veröffentlichung bereit.'];
        }

        $publication = ProductionPublication::query()
            ->where('plan_hash', $accepted['planHash'])
            ->where('source_revision', $accepted['sourceRevision'])
            ->latest('sequence')
            ->first();
        if ($publication === null) {
            return $this->withLastActive(['state' => 'ready', 'ready' => true, 'message' => 'Der übernommene Website-Stand kann jetzt öffentlich veröffentlicht werden.']);
        }
        $timeout = max(5, (int) config('publisher.production_timeout_minutes', 45));
        if (in_array($publication->status, [
            ProductionPublicationStatus::Preparing,
            ProductionPublicationStatus::Prepared,
            ProductionPublicationStatus::Dispatching,
            ProductionPublicationStatus::Dispatched,
            ProductionPublicationStatus::Building,
            ProductionPublicationStatus::Built,
            ProductionPublicationStatus::Uploading,
            ProductionPublicationStatus::Uploaded,
            ProductionPublicationStatus::Activating,
        ], true) && $publication->updated_at?->lte(now()->subMinutes($timeout))) {
            $publication = $this->lifecycle->markFailed($publication, 'workflow_timeout');
        }

        $base = [
            'ready' => false,
            'sequence' => $publication->sequence,
            'publicationId' => $publication->publication_id,
            'updatedAt' => $publication->updated_at,
        ];
        return $this->withLastActive(match ($publication->status) {
            ProductionPublicationStatus::Preparing,
            ProductionPublicationStatus::Prepared,
            ProductionPublicationStatus::Dispatching => $base + ['state' => 'queued', 'message' => 'Die öffentliche Veröffentlichung wird gestartet.'],
            ProductionPublicationStatus::Dispatched,
            ProductionPublicationStatus::Building,
            ProductionPublicationStatus::Built,
            ProductionPublicationStatus::Uploading,
            ProductionPublicationStatus::Uploaded,
            ProductionPublicationStatus::Activating => $base + ['state' => 'in_progress', 'message' => 'Die Website wird gebaut, geprüft und sicher bereitgestellt.'],
            ProductionPublicationStatus::Active => $base + [
                'state' => 'published',
                'message' => 'Dieser Website-Stand ist öffentlich veröffentlicht.',
                'publishedAt' => $publication->activated_at,
            ],
            ProductionPublicationStatus::Failed => [...$base,
                'state' => 'failed',
                'ready' => true,
                'message' => 'Die Veröffentlichung ist fehlgeschlagen. Der bisherige öffentliche Stand bleibt erhalten.',
                'failureMessage' => $this->failureMessage($publication->failure_code),
            ],
            ProductionPublicationStatus::Superseded,
            ProductionPublicationStatus::RolledBack => [...$base, 'state' => 'ready', 'ready' => true, 'message' => 'Der übernommene Website-Stand kann erneut veröffentlicht werden.'],
        });
    }

    private function acceptedState(bool $withMedia): array
    {
        $event = AuditEvent::query()
            ->where('object_type', 'publication')
            ->whereIn('action', ['apply', 'rollback'])
            ->latest('id')
            ->first();
        if ($event === null) {
            throw new DomainException('Es gibt noch keinen übernommenen Website-Stand.');
        }
        $receipt = $event->action === 'rollback'
            ? $this->verifiedRollbackReceipt($event)
            : $this->verifiedApplyReceipt($event);
        $planHash = $receipt['planHash'];
        $candidateHash = $receipt['candidateHash'];
        $resultingManifestHash = $receipt['resultingManifestHash'];

        $manifestFile = (string) config('cms.manifest');
        if (! is_file($manifestFile) || is_link($manifestFile)
            || ! hash_equals($resultingManifestHash, hash_file('sha256', $manifestFile))) {
            throw new DomainException('Der aktive Website-Stand stimmt nicht mit der letzten Übernahme überein.');
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new DomainException('Der aktive Website-Stand ist ungültig.');
        }
        $manifest['mode'] = 'release';
        $manifest['sources'] = [];
        if (is_array($manifest['projects'] ?? null)) {
            foreach ($manifest['projects'] as &$project) {
                if (is_array($project)) $project['sourceIds'] = [];
            }
            unset($project);
        }
        $this->validator->validate($manifest, true, true, ContentValidator::POLICY_PUBLICATION);

        $sourceRevision = (string) config('cms.source_revision');
        if (! preg_match('/^[0-9a-f]{40}$/D', $sourceRevision)) {
            throw new DomainException('Der freigegebene Production-Quellstand ist nicht konfiguriert.');
        }

        return [
            'candidateHash' => $candidateHash,
            'planHash' => $planHash,
            'sourceRevision' => $sourceRevision,
            'manifest' => $manifest,
            'media' => $withMedia ? $this->mediaInventory($manifest) : [],
        ];
    }

    private function verifiedApplyReceipt(AuditEvent $event): array
    {
        $planHash = (string) ($event->result_hash ?? '');
        if (! preg_match('/^[0-9a-f]{64}$/D', $planHash)) {
            throw new DomainException('Es gibt noch keinen übernommenen Website-Stand.');
        }
        $planFile = config('cms.frontend_apply_state').'/plans/'.$planHash.'.json';
        if (! is_file($planFile) || is_link($planFile)) {
            throw new DomainException('Der Beleg des übernommenen Website-Stands fehlt.');
        }
        $plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($plan) || ! hash_equals($planHash, $this->hashValue($plan))) {
            throw new DomainException('Der Beleg des übernommenen Website-Stands ist ungültig.');
        }
        $candidateHash = (string) ($plan['candidateHash'] ?? '');
        $resultingManifestHash = (string) ($plan['resultingManifestHash'] ?? '');
        if (! preg_match('/^[0-9a-f]{64}$/D', $candidateHash)
            || ! preg_match('/^[0-9a-f]{64}$/D', $resultingManifestHash)
            || ! hash_equals($candidateHash, (string) $event->object_id)) {
            throw new DomainException('Der übernommene Website-Stand ist nicht eindeutig belegt.');
        }

        return compact('candidateHash', 'planHash', 'resultingManifestHash');
    }

    private function verifiedRollbackReceipt(AuditEvent $event): array
    {
        $planHash = (string) ($event->result_hash ?? '');
        $candidateHash = (string) ($event->object_id ?? '');
        $metadata = $event->metadata;
        $userId = (int) $event->user_id;
        if (! preg_match('/^[0-9a-f]{64}$/D', $planHash)
            || ! preg_match('/^[0-9a-f]{64}$/D', $candidateHash)
            || $userId < 1
            || ! is_array($metadata)
            || array_keys($metadata) !== ['revision']) {
            throw new DomainException('Der Rollback-Beleg des übernommenen Website-Stands ist ungültig.');
        }
        $revisionId = $metadata['revision'];
        if (! is_string($revisionId) || ! preg_match('/^[0-9]{17}-[0-9a-f]{64}$/D', $revisionId)) {
            throw new DomainException('Der Rollback-Beleg des übernommenen Website-Stands ist ungültig.');
        }

        $revisionDirectory = config('cms.frontend_apply_state').'/revisions/'.$revisionId;
        $revisionFile = $revisionDirectory.'/revision.json';
        $backupManifest = $revisionDirectory.'/manifest.json';
        if (! is_file($revisionFile) || is_link($revisionFile) || ! is_file($backupManifest) || is_link($backupManifest)) {
            throw new DomainException('Der unveränderliche Rollback-Beleg fehlt.');
        }
        $revision = json_decode((string) file_get_contents($revisionFile), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($revision)
            || ($revision['version'] ?? null) !== 2
            || ! hash_equals($candidateHash, (string) ($revision['candidateHash'] ?? ''))
            || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($revision['planHash'] ?? ''))
            || ! str_ends_with($revisionId, '-'.(string) $revision['planHash'])
            || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($revision['previousManifestHash'] ?? ''))
            || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($revision['resultingManifestHash'] ?? ''))
            || ! is_array($revision['mediaChanges'] ?? null)) {
            throw new DomainException('Der unveränderliche Rollback-Beleg ist ungültig.');
        }

        $applyPlanHash = $revision['planHash'];
        $applyPlanFile = config('cms.frontend_apply_state').'/plans/'.$applyPlanHash.'.json';
        if (! is_file($applyPlanFile) || is_link($applyPlanFile)) {
            throw new DomainException('Der ursprüngliche Übernahme-Beleg des Rollbacks fehlt.');
        }
        $applyPlan = json_decode((string) file_get_contents($applyPlanFile), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($applyPlan) || ! hash_equals($applyPlanHash, $this->hashValue($applyPlan))
            || ! hash_equals($candidateHash, (string) ($applyPlan['candidateHash'] ?? ''))
            || ! hash_equals((string) $revision['previousManifestHash'], (string) ($applyPlan['previousManifestHash'] ?? ''))
            || ! hash_equals((string) $revision['resultingManifestHash'], (string) ($applyPlan['resultingManifestHash'] ?? ''))
            || $this->canonicalize($revision['mediaChanges']) !== $this->canonicalize($applyPlan['mediaChanges'] ?? null)
            || ! hash_equals((string) $revision['previousManifestHash'], hash_file('sha256', $backupManifest))) {
            throw new DomainException('Der Rollback-Beleg stimmt nicht mit der geprüften Übernahme überein.');
        }

        $rollbackPlan = [
            'operation' => 'rollback',
            'revision' => $revisionId,
            'candidateHash' => $candidateHash,
            'previousManifestHash' => $revision['resultingManifestHash'],
            'resultingManifestHash' => $revision['previousManifestHash'],
            'mediaChanges' => $revision['mediaChanges'],
            'reviewedBy' => $userId,
        ];
        if (! hash_equals($planHash, $this->hashValue($rollbackPlan))) {
            throw new DomainException('Der Rollback-Beleg wurde verändert.');
        }

        return [
            'candidateHash' => $candidateHash,
            'planHash' => $planHash,
            'resultingManifestHash' => $revision['previousManifestHash'],
        ];
    }

    private function mediaInventory(array $manifest): array
    {
        $roots = [];
        foreach (['media_root', 'managed_media_root'] as $key) {
            $configured = (string) config('cms.'.$key);
            $real = realpath($configured);
            if ($real === false || ! is_dir($real) || is_link($configured)) throw new DomainException('Das öffentliche Medienverzeichnis ist nicht sicher verfügbar.');
            $roots[$key] = rtrim(str_replace('\\', '/', $real), '/');
        }
        $media = [];
        foreach ($manifest['assets'] as $asset) {
            $path = (string) ($asset['path'] ?? '');
            if (! preg_match('#^media/[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $path) || str_contains($path, '..')) {
                throw new DomainException('Der übernommene Website-Stand enthält einen unsicheren Medienpfad.');
            }
            $rootKey = str_starts_with($path, 'media/cms/') ? 'managed_media_root' : 'media_root';
            $relative = $rootKey === 'managed_media_root' ? substr($path, 10) : substr($path, 6);
            $root = $roots[$rootKey];
            $source = realpath($root.'/'.$relative);
            $normalized = $source === false ? '' : str_replace('\\', '/', $source);
            if ($source === false || ! is_file($source) || is_link($source)
                || ! ($normalized === $root || str_starts_with($normalized, $root.'/'))) {
                throw new DomainException('Eine Datei des übernommenen Website-Stands fehlt.');
            }
            $sha256 = (string) ($asset['sha256'] ?? '');
            if (! preg_match('/^[0-9a-f]{64}$/D', $sha256) || ! hash_equals($sha256, hash_file('sha256', $source))) {
                throw new DomainException('Eine Datei des übernommenen Website-Stands stimmt nicht mit ihrer Prüfsumme überein.');
            }
            $media[] = [
                'assetId' => (string) $asset['id'],
                'type' => (string) $asset['type'],
                'publicPath' => $path,
                'sourcePath' => $source,
                'sha256' => $sha256,
                'size' => filesize($source),
            ];
        }
        return $media;
    }

    private function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function failureMessage(?string $code): string
    {
        return match ($code) {
            'workflow_dispatch_failed' => 'Der automatische Start ist fehlgeschlagen. Bitte prüfen Sie die Konfiguration oder versuchen Sie es erneut.',
            'workflow_timeout' => 'Die Veröffentlichung hat nicht rechtzeitig geantwortet und wurde sicher beendet. Bitte versuchen Sie es erneut.',
            'build_failed', 'build_validation_failed', 'package_download_failed', 'package_verification_failed' => 'Der neue Website-Stand konnte nicht sicher gebaut oder geprüft werden.',
            'upload_failed', 'activation_failed' => 'Der geprüfte Website-Stand konnte nicht sicher aktiviert werden.',
            default => 'Die Veröffentlichung wurde sicher abgebrochen.',
        };
    }

    private function withLastActive(array $status): array
    {
        $active = ProductionPublication::currentActive();
        if ($active !== null) {
            $status['lastPublishedAt'] = $active->activated_at;
            $status['lastPublishedSequence'] = $active->sequence;
        }
        return $status;
    }
}
