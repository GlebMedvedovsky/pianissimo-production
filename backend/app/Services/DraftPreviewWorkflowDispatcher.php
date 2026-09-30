<?php

namespace App\Services;

use App\Models\DraftPreview;
use DomainException;
use Illuminate\Support\Facades\Http;

final class DraftPreviewWorkflowDispatcher
{
    public function dispatch(DraftPreview $preview): void
    {
        $metadata = app(DraftPreviewPackageService::class)->metadata($preview);
        $repository = trim((string) config('publisher.github.repository'));
        $ref = trim((string) config('publisher.github.ref'));
        $workflow = trim((string) config('publisher.github.preview_workflow'));
        $token = (string) config('publisher.github.token');
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repository)
            || $ref === '' || strlen($ref) > 255 || ! preg_match('#^[A-Za-z0-9._/-]+$#D', $ref) || str_contains($ref, '..') || str_contains($ref, '//')
            || ! preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $workflow)
            || strlen($token) < 32 || strlen($token) > 512 || preg_match('/\s/', $token)) {
            throw new DomainException('Die Paket-Vorschau ist noch nicht vollständig konfiguriert.');
        }
        $response = Http::timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post("https://api.github.com/repos/{$repository}/actions/workflows/{$workflow}/dispatches", [
                'ref' => $ref,
                'inputs' => [
                    'preview_identifier' => $metadata['previewIdentifier'],
                    'package_sha256' => $metadata['packageSha256'],
                    'source_revision' => $metadata['sourceRevision'],
                ],
            ]);
        if ($response->status() !== 204) throw new DomainException('Der Vorschau-Build konnte nicht gestartet werden.');
    }
}
