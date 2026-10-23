<?php

namespace App\Services;

use App\Models\{AudioAnalysisJob, MediaAsset, Track};
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackAudioMetadata
{
    public function __construct(private readonly AudioAnalysisWorkflowDispatcher $dispatcher) {}

    public static function emptyMetadata(string $status = 'missing', ?string $error = null, ?string $jobId = null): array
    {
        return ['duration' => null, 'peaks' => null, 'audio_sha256' => null, 'waveform_sha256' => null,
            'audio_metadata_error' => $error, 'audio_metadata_status' => $status, 'audio_analysis_job_id' => $jobId,
            'audio_metadata_integrity_sha256' => null];
    }

    public static function waveformSha256(array $peaks): string
    {
        $canonical = array_map(
            static fn (mixed $peak): string => number_format((float) $peak, 6, '.', ''),
            array_values($peaks),
        );
        return hash('sha256', implode(',', $canonical));
    }

    public static function metadataIntegritySha256(float $duration, array $peaks, string $audioSha, string $waveformSha): string
    {
        $numbers = array_map(static fn (mixed $peak): string => number_format((float) $peak, 10, '.', ''), array_values($peaks));
        return hash('sha256', number_format($duration, 6, '.', '')."\n".implode(',', $numbers)."\n{$audioSha}\n{$waveformSha}");
    }

    public function prepareForSave(Track $track): void
    {
        if (! $track->isDirty('audio_asset_id')) return;
        $track->forceFill(self::emptyMetadata($track->audio_asset_id === null ? 'missing' : 'pending'));
    }

    public function dispatchForTrack(Track $track, bool $force = false): ?AudioAnalysisJob
    {
        if ($track->audio_asset_id === null) return null;
        $asset = MediaAsset::find($track->audio_asset_id);
        if (! $asset || $asset->type !== 'audio') {
            $track->forceFill(self::emptyMetadata('failed', 'Verarbeitung fehlgeschlagen.'))->saveQuietly();
            return null;
        }
        return $this->dispatchForAsset($asset, $force);
    }

    public function dispatchForAsset(MediaAsset $asset, bool $force = false): ?AudioAnalysisJob
    {
        if ($asset->type !== 'audio' || ! is_string($asset->sha256) || preg_match('/^[a-f0-9]{64}$/D', $asset->sha256) !== 1) {
            Track::where('audio_asset_id', $asset->id)->update(self::emptyMetadata('failed', 'Verarbeitung fehlgeschlagen.'));
            return null;
        }
        $created = false;
        $job = DB::transaction(function () use ($asset, $force, &$created): AudioAnalysisJob {
            $active = AudioAnalysisJob::query()->where('media_asset_id', $asset->id)
                ->where('expected_audio_sha256', $asset->sha256)->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()->latest('created_at')->first();
            if (! $force && ! $active) {
                $ready = AudioAnalysisJob::query()->where('media_asset_id', $asset->id)
                    ->where('expected_audio_sha256', $asset->sha256)->where('status', 'ready')
                    ->lockForUpdate()->latest('created_at')->first();
                $metadata = $ready ? $this->metadataFromReadyJob($ready) : null;
                if ($ready && $metadata) {
                    Track::query()->where('audio_asset_id', $asset->id)->update([
                        ...$metadata, 'audio_metadata_error' => null, 'audio_metadata_status' => 'ready',
                        'audio_analysis_job_id' => $ready->id,
                    ]);
                    return $ready;
                }
            }
            if ($force || ! $active) {
                AudioAnalysisJob::query()->where('media_asset_id', $asset->id)
                    ->whereIn('status', ['pending', 'processing'])->update(['status' => 'superseded']);
                $active = AudioAnalysisJob::create(['id' => bin2hex(random_bytes(16)), 'media_asset_id' => $asset->id,
                    'expected_audio_sha256' => $asset->sha256, 'status' => 'pending']);
                $created = true;
            }
            Track::query()->where('audio_asset_id', $asset->id)->update(self::emptyMetadata($active->status, null, $active->id));
            return $active;
        });
        if (! $created) return $job;
        try {
            $this->dispatcher->dispatch($job);
        } catch (\Throwable) {
            $this->failJob($job, 'dispatch_failed');
            return $job->fresh();
        }
        return $job;
    }

    public function retry(Track $track): ?AudioAnalysisJob
    {
        return $this->dispatchForTrack($track, true);
    }

    public function claimSource(string $assetId, string $expectedSha, string $jobId): array
    {
        return DB::transaction(function () use ($assetId, $expectedSha, $jobId): array {
            $job = $this->currentJob($assetId, $expectedSha, $jobId, ['pending']);
            $asset = MediaAsset::query()->lockForUpdate()->findOrFail($assetId);
            if ($asset->type !== 'audio' || ! is_string($asset->sha256) || ! hash_equals($expectedSha, $asset->sha256)) {
                throw new DomainException('Audio analysis asset identity is stale.');
            }
            $job->update(['status' => 'processing', 'failure_code' => null]);
            Track::query()->where('audio_asset_id', $assetId)->where('audio_analysis_job_id', $jobId)
                ->update(['audio_metadata_status' => 'processing', 'audio_metadata_error' => null]);
            return ['asset' => $asset, 'job' => $job];
        });
    }

    public function acceptResult(string $assetId, array $payload): void
    {
        $keys = array_keys($payload); sort($keys);
        $expectedKeys = ['analysisJobId', 'assetId', 'audioSha256', 'duration', 'peaks', 'status', 'waveformSha256']; sort($expectedKeys);
        $expectedSha = (string) ($payload['audioSha256'] ?? '');
        $jobId = (string) ($payload['analysisJobId'] ?? '');
        $duration = $payload['duration'] ?? null;
        $peaks = $payload['peaks'] ?? null;
        $waveformSha = (string) ($payload['waveformSha256'] ?? '');
        if ($keys !== $expectedKeys || ($payload['status'] ?? null) !== 'success' || ($payload['assetId'] ?? null) !== $assetId || preg_match('/^[a-f0-9]{64}$/D', $expectedSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1 || (! is_int($duration) && ! is_float($duration))
            || ! is_finite((float) $duration) || $duration <= 0 || $duration > 86400
            || ! is_array($peaks) || count($peaks) !== (int) config('audio-analysis.waveform_samples', 96)
            || preg_match('/^[a-f0-9]{64}$/D', $waveformSha) !== 1) throw new DomainException('Audio analysis result is invalid.');
        $canonical = [];
        foreach (array_values($peaks) as $peak) {
            if ((! is_int($peak) && ! is_float($peak)) || ! is_finite((float) $peak) || $peak < 0 || $peak > 1
                || abs((float) $peak - round((float) $peak, 6)) > 0.0000001) throw new DomainException('Audio analysis peaks are invalid.');
            $canonical[] = round((float) $peak, 6);
        }
        $maximum = max($canonical);
        if ($maximum !== 0.0 && abs($maximum - 1.0) > 0.0000001) throw new DomainException('Audio analysis peaks are not normalized.');
        if (! hash_equals(self::waveformSha256($canonical), $waveformSha)) throw new DomainException('Audio analysis waveform digest is invalid.');
        DB::transaction(function () use ($assetId, $expectedSha, $jobId, $duration, $canonical, $waveformSha): void {
            $job = $this->currentJob($assetId, $expectedSha, $jobId, ['processing']);
            $asset = MediaAsset::query()->lockForUpdate()->findOrFail($assetId);
            if ($asset->type !== 'audio' || ! is_string($asset->sha256) || ! hash_equals($expectedSha, $asset->sha256)) throw new DomainException('Audio analysis asset identity is stale.');
            Track::query()->where('audio_asset_id', $assetId)->where('audio_analysis_job_id', $jobId)->update([
                'duration' => round((float) $duration, 6), 'peaks' => json_encode($canonical, JSON_THROW_ON_ERROR),
                'audio_sha256' => $expectedSha, 'waveform_sha256' => $waveformSha,
                'audio_metadata_error' => null, 'audio_metadata_status' => 'ready',
                'audio_metadata_integrity_sha256' => self::metadataIntegritySha256((float) $duration, $canonical, $expectedSha, $waveformSha),
            ]);
            $job->update([
                'status' => 'ready', 'failure_code' => null, 'duration' => round((float) $duration, 6),
                'peaks' => $canonical, 'waveform_sha256' => $waveformSha,
                'metadata_integrity_sha256' => self::metadataIntegritySha256((float) $duration, $canonical, $expectedSha, $waveformSha),
            ]);
        });
    }

    public function reportFailure(string $assetId, array $payload): void
    {
        $keys = array_keys($payload); sort($keys);
        $expectedKeys = ['analysisJobId', 'assetId', 'audioSha256', 'failureCode', 'status']; sort($expectedKeys);
        $expectedSha = (string) ($payload['audioSha256'] ?? '');
        $jobId = (string) ($payload['analysisJobId'] ?? '');
        $failureCode = (string) ($payload['failureCode'] ?? 'worker_failed');
        if ($keys !== $expectedKeys || ($payload['status'] ?? null) !== 'failed' || ($payload['assetId'] ?? null) !== $assetId || preg_match('/^[a-f0-9]{64}$/D', $expectedSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1 || preg_match('/^[a-z0-9_]{1,64}$/D', $failureCode) !== 1) {
            throw new DomainException('Audio analysis failure result is invalid.');
        }
        $this->failJob($this->currentJob($assetId, $expectedSha, $jobId, ['pending', 'processing']), $failureCode);
    }

    public function needsRefresh(Track $track): bool
    {
        if ($track->audio_asset_id === null) return false;
        $asset = MediaAsset::find($track->audio_asset_id);
        if (! $asset || $track->audio_metadata_status !== 'ready' || ! is_numeric($track->duration) || $track->duration <= 0
            || ! is_array($track->peaks) || count($track->peaks) !== (int) config('audio-analysis.waveform_samples', 96)
            || ! is_string($track->audio_sha256) || ! is_string($track->waveform_sha256)
            || ! is_string($asset->sha256) || ! hash_equals($asset->sha256, $track->audio_sha256)) return true;
        return ! hash_equals($track->waveform_sha256, self::waveformSha256($track->peaks));
    }

    public function status(Track $track): string
    {
        $status = (string) ($track->audio_metadata_status ?: 'missing');
        if ($status === 'ready' && $this->needsRefresh($track)) return 'stale';
        return in_array($status, ['missing', 'pending', 'processing', 'ready', 'failed'], true) ? $status : 'missing';
    }

    public function publicationReady(Track $track): bool
    {
        if ($track->audio_asset_id === null || $track->audio_metadata_status !== 'ready' || ! is_numeric($track->duration)
            || $track->duration <= 0 || ! is_array($track->peaks) || $track->peaks === []
            || ! is_string($track->audio_sha256) || ! is_string($track->waveform_sha256)
            || ! is_string($track->audio_metadata_integrity_sha256)) return false;
        $asset = MediaAsset::find($track->audio_asset_id);
        if (! $asset || $asset->type !== 'audio' || ! is_string($asset->sha256) || ! hash_equals($asset->sha256, $track->audio_sha256)) return false;
        foreach ($track->peaks as $peak) if (! is_numeric($peak) || ! is_finite((float) $peak) || $peak < 0 || $peak > 1) return false;
        $integrity = self::metadataIntegritySha256((float) $track->duration, $track->peaks, $track->audio_sha256, $track->waveform_sha256);
        return preg_match('/^[a-f0-9]{64}$/D', $track->waveform_sha256) === 1 && hash_equals($track->audio_metadata_integrity_sha256, $integrity);
    }

    private function currentJob(string $assetId, string $expectedSha, string $jobId, array $statuses): AudioAnalysisJob
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $expectedSha) !== 1) throw new DomainException('Audio analysis job identity is invalid.');
        $job = AudioAnalysisJob::query()->lockForUpdate()->find($jobId);
        if (! $job || $job->media_asset_id !== $assetId || ! hash_equals($job->expected_audio_sha256, $expectedSha)
            || ! in_array($job->status, $statuses, true)) throw new DomainException('Audio analysis job is stale.');
        return $job;
    }

    private function metadataFromReadyJob(AudioAnalysisJob $job): ?array
    {
        if (! is_numeric($job->duration) || $job->duration <= 0 || ! is_array($job->peaks)
            || count($job->peaks) !== (int) config('audio-analysis.waveform_samples', 96)
            || ! is_string($job->waveform_sha256) || preg_match('/^[a-f0-9]{64}$/D', $job->waveform_sha256) !== 1
            || ! is_string($job->metadata_integrity_sha256)) return null;
        foreach ($job->peaks as $peak) {
            if (! is_numeric($peak) || ! is_finite((float) $peak) || $peak < 0 || $peak > 1) return null;
        }
        if (! hash_equals(self::waveformSha256($job->peaks), $job->waveform_sha256)) return null;
        $integrity = self::metadataIntegritySha256((float) $job->duration, $job->peaks, $job->expected_audio_sha256, $job->waveform_sha256);
        if (! hash_equals($job->metadata_integrity_sha256, $integrity)) return null;
        return [
            'duration' => $job->duration, 'peaks' => json_encode($job->peaks, JSON_THROW_ON_ERROR),
            'audio_sha256' => $job->expected_audio_sha256, 'waveform_sha256' => $job->waveform_sha256,
            'audio_metadata_integrity_sha256' => $job->metadata_integrity_sha256,
        ];
    }

    private function failJob(AudioAnalysisJob $job, string $failureCode): void
    {
        DB::transaction(function () use ($job, $failureCode): void {
            $locked = AudioAnalysisJob::query()->lockForUpdate()->find($job->id);
            if (! $locked || ! in_array($locked->status, ['pending', 'processing'], true)) return;
            $locked->update(['status' => 'failed', 'failure_code' => Str::limit($failureCode, 64, '')]);
            Track::query()->where('audio_asset_id', $locked->media_asset_id)->where('audio_analysis_job_id', $locked->id)
                ->update(self::emptyMetadata('failed', 'Verarbeitung fehlgeschlagen.', $locked->id));
        });
    }
}
