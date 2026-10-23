<?php

namespace Tests\Feature;

use App\Models\{AudioAnalysisJob, MediaAsset, Track};
use App\Services\{MediaStorage, TrackAudioMetadata};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File, Http};
use Tests\TestCase;

class AudioAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/audio-analysis-api-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->root);
        $this->token = str_repeat('a', 48);
        config([
            'cms.media_originals_root' => $this->root,
            'audio-analysis.api_token' => $this->token,
            'audio-analysis.github.repository' => 'owner/repository',
            'audio-analysis.github.ref' => 'main',
            'audio-analysis.github.workflow' => 'pianissimo-audio-analysis.yml',
            'audio-analysis.github.dispatch_token' => str_repeat('d', 48),
            'audio-analysis.waveform_samples' => 96,
            'publisher.api_token' => str_repeat('p', 48),
            'publisher.content_sync_api_token' => str_repeat('s', 48),
        ]);
        Http::fake(['https://api.github.com/*' => Http::response('', 204)]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_source_requires_dedicated_token_and_never_exposes_paths(): void
    {
        [$asset, $track, $job] = $this->pending('protected-audio');
        $url = $this->sourceUrl($asset, $job);
        $this->get($url)->assertUnauthorized()->assertJsonMissingPath('path');
        foreach ([config('publisher.api_token'), config('publisher.content_sync_api_token')] as $wrongToken) {
            $this->withToken($wrongToken)->get($url)->assertUnauthorized();
        }
        $response = $this->withToken($this->token)->get($url)->assertOk()
            ->assertHeader('ETag', '"'.$asset->sha256.'"')->assertHeader('X-Audio-SHA256', $asset->sha256);
        $this->assertSame(file_get_contents($this->file($asset)), $response->streamedContent());
        $this->assertStringNotContainsString($this->root, $response->getContent() ?: '');
        $this->assertSame('processing', $track->fresh()->audio_metadata_status);
    }

    public function test_non_audio_arbitrary_path_and_stale_identity_are_rejected_generically(): void
    {
        $image = MediaAsset::create($this->assetData('image-asset', 'image', str_repeat('b', 64), 'png', 'media/originals/image-asset/'.str_repeat('b', 64).'.png'));
        $job = AudioAnalysisJob::create(['id' => str_repeat('1', 32), 'media_asset_id' => $image->id, 'expected_audio_sha256' => $image->sha256, 'status' => 'pending']);
        $response = $this->withToken($this->token)->get($this->sourceUrl($image, $job))->assertConflict();
        $this->assertSame(['message' => 'Audio analysis source unavailable.'], $response->json());
        $this->withToken($this->token)->get('/api/audio-analysis/v1/assets/../source')->assertNotFound();

        [$asset, , $audioJob] = $this->pending('stale-source');
        $this->withToken($this->token)->get('/api/audio-analysis/v1/assets/'.$asset->id.'/source?expectedSha='.str_repeat('0', 64).'&analysisJobId='.$audioJob->id)
            ->assertConflict()->assertJsonMissingPath('path');
    }

    public function test_valid_result_is_accepted_once_and_malformed_or_mismatched_results_are_rejected(): void
    {
        [$asset, $track, $job] = $this->pending('result-audio');
        $this->withToken($this->token)->get($this->sourceUrl($asset, $job))->assertOk();
        $payload = $this->validPayload($asset, $job);

        $badPeaks = $payload;
        $badPeaks['peaks'][4] = 1.5;
        $this->withToken($this->token)->postJson($this->resultUrl($asset), $badPeaks)->assertConflict();
        $badDigest = $payload;
        $badDigest['waveformSha256'] = str_repeat('0', 64);
        $this->withToken($this->token)->postJson($this->resultUrl($asset), $badDigest)->assertConflict();

        $this->withToken($this->token)->postJson($this->resultUrl($asset), $payload)->assertOk();
        $track->refresh();
        $this->assertSame('ready', $track->audio_metadata_status);
        $this->assertSame($asset->sha256, $track->audio_sha256);
        $this->assertCount(96, $track->peaks);
        $this->withToken($this->token)->postJson($this->resultUrl($asset), $payload)->assertConflict();
    }

    public function test_replacement_supersedes_old_job_and_old_callback_cannot_overwrite_track(): void
    {
        [$asset, $track, $oldJob] = $this->pending('replace-callback');
        $oldPayload = $this->validPayload($asset, $oldJob);
        $replacement = 'media/originals/'.$asset->id.'/replacement.wav';
        File::put($this->root.'/'.$asset->id.'/replacement.wav', $this->wav());
        app(MediaStorage::class)->replace($asset, $replacement, 'replacement.wav');
        $track->refresh();
        $newJob = $track->audio_analysis_job_id;

        $this->assertNotSame($oldJob->id, $newJob);
        $this->assertSame('superseded', $oldJob->fresh()->status);
        $this->withToken($this->token)->postJson($this->resultUrl($asset), $oldPayload)->assertConflict();
        $this->assertSame($newJob, $track->fresh()->audio_analysis_job_id);
        $this->assertNull($track->fresh()->duration);
    }

    private function pending(string $id): array
    {
        $bytes = 'canonical audio bytes '.$id;
        $sha = hash('sha256', $bytes);
        $relative = "media/originals/$id/$sha.wav";
        File::ensureDirectoryExists($this->root.'/'.$id);
        File::put($this->root.'/'.$id.'/'.$sha.'.wav', $bytes);
        $asset = MediaAsset::create($this->assetData($id, 'audio', $sha, 'wav', $relative));
        $track = Track::create(['id' => $id.'-track', 'title' => $id, 'audio_asset_id' => $id, 'genres' => [], 'emotions' => [],
            'platform_overrides' => [], 'status' => 'draft', 'rights_scope' => 'existing_local_draft', 'library_visible' => false])->fresh();
        return [$asset, $track, AudioAnalysisJob::findOrFail($track->audio_analysis_job_id)];
    }

    private function validPayload(MediaAsset $asset, AudioAnalysisJob $job): array
    {
        $peaks = array_fill(0, 96, 0.25);
        $peaks[95] = 1.0;
        return ['status' => 'success', 'assetId' => $asset->id, 'audioSha256' => $asset->sha256,
            'analysisJobId' => $job->id, 'duration' => 12.5, 'peaks' => $peaks,
            'waveformSha256' => TrackAudioMetadata::waveformSha256($peaks)];
    }

    private function sourceUrl(MediaAsset $asset, AudioAnalysisJob $job): string
    {
        return '/api/audio-analysis/v1/assets/'.$asset->id.'/source?expectedSha='.$job->expected_audio_sha256.'&analysisJobId='.$job->id;
    }

    private function resultUrl(MediaAsset $asset): string { return '/api/audio-analysis/v1/assets/'.$asset->id.'/result'; }
    private function file(MediaAsset $asset): string { return $this->root.'/'.$asset->id.'/'.$asset->sha256.'.wav'; }
    private function wav(): string
    {
        $pcm = str_repeat("\0\0", 800);
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function assetData(string $id, string $type, string $sha, string $extension, string $storagePath): array
    {
        return ['id' => $id, 'path' => "media/cms/$id-".substr($sha, 0, 16).".$extension", 'storage_path' => $storagePath,
            'type' => $type, 'purpose' => $type === 'audio' ? 'audio' : 'other', 'alt' => '', 'crop' => ['x' => 50, 'y' => 50],
            'state' => 'temporary', 'rights_scope' => 'existing_local_draft', 'sha256' => $sha, 'mime_type' => $type === 'audio' ? 'audio/wav' : 'image/png'];
    }
}
