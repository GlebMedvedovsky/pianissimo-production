<?php

namespace App\Services;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProductionWorkflowDispatcher
{
    /** @return array{publication_id:string,sequence:string,package_sha256:string,source_revision:string} */
    public function payload(ProductionPublication $publication): array
    {
        $publication->refresh();
        if (! in_array($publication->status, [
            ProductionPublicationStatus::Prepared,
            ProductionPublicationStatus::Dispatching,
            ProductionPublicationStatus::Dispatched,
        ], true)) {
            throw new DomainException('Only a prepared, dispatching, or dispatched production publication can be dispatched.');
        }
        if (! preg_match('/^[0-9a-f-]{36}$/', $publication->publication_id)
            || $publication->sequence < 1
            || ! preg_match('/^[0-9a-f]{64}$/', $publication->package_sha256)
            || ! preg_match('/^[0-9a-f]{40}$/', $publication->source_revision)) {
            throw new DomainException('Production workflow dispatch inputs are invalid.');
        }

        return [
            'publication_id' => $publication->publication_id,
            'sequence' => (string) $publication->sequence,
            'package_sha256' => $publication->package_sha256,
            'source_revision' => $publication->source_revision,
        ];
    }

    public function dispatch(ProductionPublication $publication): void
    {
        if (config('publisher.production_automation_enabled') !== true) {
            throw new DomainException('Die automatische Production-Veröffentlichung ist noch nicht freigeschaltet.');
        }
        $repository = trim((string) config('publisher.github.repository'));
        $ref = trim((string) config('publisher.github.ref'));
        $workflow = trim((string) config('publisher.github.workflow'));
        $token = (string) config('publisher.github.token');
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repository)
            || $ref === '' || strlen($ref) > 255 || ! preg_match('#^[A-Za-z0-9._/-]+$#D', $ref) || str_contains($ref, '..') || str_contains($ref, '//')
            || ! preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $workflow)
            || strlen($token) < 32 || strlen($token) > 512 || preg_match('/\s/', $token)) {
            throw new DomainException('Die Production-Veröffentlichung ist noch nicht vollständig konfiguriert.');
        }

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->post("https://api.github.com/repos/{$repository}/actions/workflows/{$workflow}/dispatches", [
                    'ref' => $ref,
                    'inputs' => $this->payload($publication),
                ]);
        } catch (Throwable) {
            throw new DomainException('Die Production-Veröffentlichung konnte nicht gestartet werden.');
        }
        if ($response->status() !== 204) {
            throw new DomainException('Die Production-Veröffentlichung konnte nicht gestartet werden.');
        }
    }
}
