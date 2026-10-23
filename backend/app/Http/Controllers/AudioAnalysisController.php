<?php

namespace App\Http\Controllers;

use App\Services\{MediaStorage, TrackAudioMetadata};
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AudioAnalysisController
{
    public function __construct(private readonly TrackAudioMetadata $metadata, private readonly MediaStorage $storage) {}

    public function source(Request $request, string $assetId): StreamedResponse|JsonResponse
    {
        try {
            $expectedSha = (string) $request->query('expectedSha', '');
            $jobId = (string) $request->query('analysisJobId', '');
            $claimed = $this->metadata->claimSource($assetId, $expectedSha, $jobId);
            $asset = $claimed['asset'];
            if (! is_string($asset->storage_path) || $asset->storage_path === '') throw new DomainException('Canonical audio source is unavailable.');
            $file = $this->storage->resolveStored($asset->storage_path);
            $actualSha = hash_file('sha256', $file);
            $size = filesize($file);
            if (! is_string($actualSha) || ! hash_equals($expectedSha, $actualSha) || ! is_int($size)) throw new DomainException('Canonical audio source is invalid.');
            $mime = match ($asset->mime_type) {
                'audio/mpeg' => 'audio/mpeg',
                'audio/wav', 'audio/x-wav' => 'audio/wav',
                'audio/ogg', 'application/ogg' => 'audio/ogg',
                default => 'application/octet-stream',
            };
            return response()->stream(static function () use ($file): void {
                $handle = fopen($file, 'rb');
                if ($handle === false) return;
                try { fpassthru($handle); } finally { fclose($handle); }
            }, 200, [
                'Content-Type' => $mime,
                'Content-Length' => (string) $size,
                'ETag' => '"'.$expectedSha.'"',
                'X-Audio-SHA256' => $expectedSha,
            ]);
        } catch (ModelNotFoundException) {
            return $this->error('Audio analysis asset unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Audio analysis source unavailable.', 409);
        } catch (\Throwable) {
            return $this->error('Audio analysis request failed.', 500);
        }
    }

    public function result(Request $request, string $assetId): JsonResponse
    {
        try {
            $payload = $request->json()->all();
            if (($payload['status'] ?? 'success') === 'failed') $this->metadata->reportFailure($assetId, $payload);
            else $this->metadata->acceptResult($assetId, $payload);
            return response()->json(['status' => 'accepted']);
        } catch (ModelNotFoundException) {
            return $this->error('Audio analysis asset unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Audio analysis result rejected.', 409);
        } catch (\Throwable) {
            return $this->error('Audio analysis request failed.', 500);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'private, no-store']);
    }
}
