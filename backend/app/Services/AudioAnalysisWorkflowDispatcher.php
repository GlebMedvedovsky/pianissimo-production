<?php

namespace App\Services;

use App\Models\AudioAnalysisJob;
use DomainException;
use Illuminate\Support\Facades\Http;

final class AudioAnalysisWorkflowDispatcher
{
    public function dispatch(AudioAnalysisJob $job): void
    {
        $repository = trim((string) config('audio-analysis.github.repository'));
        $ref = trim((string) config('audio-analysis.github.ref'));
        $workflow = trim((string) config('audio-analysis.github.workflow'));
        $token = (string) config('audio-analysis.github.dispatch_token');
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repository) !== 1
            || $ref === '' || strlen($ref) > 255 || preg_match('#^[A-Za-z0-9._/-]+$#D', $ref) !== 1
            || str_contains($ref, '..') || str_contains($ref, '//')
            || preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $workflow) !== 1
            || strlen($token) < 32 || strlen($token) > 512 || preg_match('/\s/', $token) === 1) {
            throw new DomainException('Die Audio-Verarbeitung ist noch nicht vollständig konfiguriert.');
        }
        $response = Http::timeout(30)->withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post("https://api.github.com/repos/{$repository}/actions/workflows/{$workflow}/dispatches", [
                'ref' => $ref,
                'inputs' => [
                    'asset_id' => $job->media_asset_id,
                    'expected_audio_sha256' => $job->expected_audio_sha256,
                    'analysis_job_id' => $job->id,
                ],
            ]);
        if ($response->status() !== 204) throw new DomainException('Die Audio-Verarbeitung konnte nicht gestartet werden.');
    }
}
