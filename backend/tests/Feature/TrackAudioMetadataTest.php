<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\CreateMediaAsset;
use App\Filament\Resources\Pages\EditTrack;
use App\Models\{AudioAnalysisJob, MediaAsset, Track, User};
use App\Services\{FfmpegAudioAnalyzer, MediaStorage, TrackAudioMetadata};
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan, File, Http};
use Livewire\Livewire;
use Tests\TestCase;

class TrackAudioMetadataTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/audio-metadata-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->fixtureRoot);
        config([
            'cms.media_originals_root' => $this->fixtureRoot,
            'cms.audio_ffprobe_path' => '/usr/bin/ffprobe',
            'cms.audio_ffmpeg_path' => '/usr/bin/ffmpeg',
            'cms.audio_waveform_samples' => 96,
            'cms.audio_waveform_sample_rate' => 8000,
            'cms.audio_analysis_timeout_seconds' => 30,
            'cms.audio_analysis_max_decoded_bytes' => 8 * 1024 * 1024,
            'audio-analysis.api_token' => str_repeat('a', 48),
            'audio-analysis.github.repository' => 'owner/pianissimo',
            'audio-analysis.github.ref' => 'main',
            'audio-analysis.github.workflow' => 'pianissimo-audio-analysis.yml',
            'audio-analysis.github.dispatch_token' => str_repeat('d', 48),
            'audio-analysis.waveform_samples' => 96,
        ]);
        Http::fake(['https://api.github.com/*' => Http::response('', 204)]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_new_audio_assignment_creates_pending_analysis_and_dispatches_exact_identity(): void
    {
        $asset = $this->audio('tone', 1.25, 5000, 22000);
        $track = $this->track('first', $asset)->fresh();
        $job = AudioAnalysisJob::findOrFail($track->audio_analysis_job_id);
        $this->assertSame('pending', $track->audio_metadata_status);
        $this->assertNull($track->duration);
        $this->assertSame($asset->sha256, $job->expected_audio_sha256);
        Http::assertSent(fn ($request) => $request['inputs'] === [
            'asset_id' => $asset->id, 'expected_audio_sha256' => $asset->sha256, 'analysis_job_id' => $job->id,
        ]);
    }

    public function test_new_unassigned_audio_upload_is_analyzed_and_ready_result_is_reused_on_first_assignment(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::create(['name' => 'Upload QA', 'email' => 'upload-audio@example.test', 'password' => bin2hex(random_bytes(20))]);
        $this->actingAs($user);
        Livewire::test(CreateMediaAsset::class)->fillForm([
            '_mode' => 'upload', 'id' => 'uploaded-before-track',
            'storage_path' => UploadedFile::fake()->createWithContent('new.wav', $this->wav(1.0, 4000, 18000)),
            'purpose' => 'audio', 'alt' => '', 'state' => 'temporary', 'rights_scope' => 'existing_local_draft',
            'crop' => ['x' => 50, 'y' => 50],
        ])->call('create')->assertHasNoFormErrors();
        $asset = MediaAsset::findOrFail('uploaded-before-track');
        $job = AudioAnalysisJob::query()->where('media_asset_id', $asset->id)->sole();
        $this->assertSame('pending', $job->status);
        $this->assertDatabaseCount('tracks', 0);

        $analysis = app(FfmpegAudioAnalyzer::class)->analyze($this->fixtureRoot.'/uploaded-before-track/'.$asset->sha256.'.wav');
        $service = app(TrackAudioMetadata::class);
        $service->claimSource($asset->id, $asset->sha256, $job->id);
        $service->acceptResult($asset->id, [
            'status' => 'success', 'assetId' => $asset->id, 'audioSha256' => $asset->sha256, 'analysisJobId' => $job->id,
            'duration' => $analysis['duration'], 'peaks' => $analysis['peaks'], 'waveformSha256' => $analysis['waveform_sha256'],
        ]);
        $dispatchCount = Http::recorded()->count();

        $track = $this->track('assigned-after-analysis', $asset)->fresh();
        $this->assertSame('ready', $track->audio_metadata_status);
        $this->assertSame($job->id, $track->audio_analysis_job_id);
        $this->assertSame($asset->sha256, $track->audio_sha256);
        $this->assertCount(96, $track->peaks);
        $this->assertSame($dispatchCount, Http::recorded()->count(), 'A verified ready asset must not be analyzed twice.');
    }

    public function test_first_audio_assignment_to_an_existing_track_creates_pending_analysis(): void
    {
        $asset = $this->audio('existing-track-first-audio', 0.5, 3500, 12000);
        $track = Track::create([
            'id' => 'existing-without-audio', 'title' => 'Existing without audio', 'audio_asset_id' => null,
            'genres' => [], 'emotions' => [], 'platform_overrides' => [], 'status' => 'draft',
            'rights_scope' => 'existing_local_draft', 'library_visible' => false,
        ]);

        $track->update(['audio_asset_id' => $asset->id]);
        $track->refresh();
        $this->assertSame('pending', $track->audio_metadata_status);
        $this->assertNotNull($track->audio_analysis_job_id);
        $this->assertSame($asset->sha256, AudioAnalysisJob::findOrFail($track->audio_analysis_job_id)->expected_audio_sha256);
    }

    public function test_successful_result_produces_verified_ready_metadata(): void
    {
        $asset = $this->audio('callback-tone', 1.25, 5000, 22000);
        $track = $this->track('callback-track', $asset)->fresh();
        $job = AudioAnalysisJob::findOrFail($track->audio_analysis_job_id);
        $analysis = app(FfmpegAudioAnalyzer::class)->analyze($this->fixtureRoot.'/callback-tone/'.$asset->sha256.'.wav');
        $service = app(TrackAudioMetadata::class);
        $service->claimSource($asset->id, $asset->sha256, $job->id);
        $service->acceptResult($asset->id, [
            'status' => 'success', 'assetId' => $asset->id, 'audioSha256' => $asset->sha256, 'analysisJobId' => $job->id,
            'duration' => $analysis['duration'], 'peaks' => $analysis['peaks'], 'waveformSha256' => $analysis['waveform_sha256'],
        ]);
        $track->refresh();
        $this->assertSame($asset->sha256, $track->audio_sha256);
        $this->assertCount(96, $track->peaks);
        $this->assertSame(TrackAudioMetadata::waveformSha256($track->peaks), $track->waveform_sha256);
        $this->assertSame('ready', app(TrackAudioMetadata::class)->status($track));
    }

    public function test_replacing_an_audio_asset_transactionally_refreshes_every_referencing_track(): void
    {
        $asset = $this->audio('replace-asset', 0.5, 3000, 9000);
        $track = $this->track('replace-asset-track', $asset);
        $oldJob = $track->fresh()->audio_analysis_job_id;
        $relative = 'media/originals/replace-asset/replacement.wav';
        file_put_contents($this->fixtureRoot.'/replace-asset/replacement.wav', $this->wav(1.25, 7000, 26000));

        app(MediaStorage::class)->replace($asset, $relative, 'replacement.wav');
        $asset->refresh();
        $track->refresh();

        $this->assertSame('superseded', AudioAnalysisJob::findOrFail($oldJob)->status);
        $this->assertNotSame($oldJob, $track->audio_analysis_job_id);
        $this->assertSame('pending', $track->audio_metadata_status);
        $this->assertNull($track->duration);
        $this->assertSame($asset->sha256, AudioAnalysisJob::findOrFail($track->audio_analysis_job_id)->expected_audio_sha256);
    }

    public function test_failed_dispatch_is_retryable_with_a_new_job_identity(): void
    {
        config(['audio-analysis.github.dispatch_token' => 'invalid']);
        $track = $this->track('failure-case', $this->audio('failure-tone', 0.5, 5000, 14000))->fresh();
        $failedJob = $track->audio_analysis_job_id;
        $this->assertSame('failed', $track->audio_metadata_status);
        config(['audio-analysis.github.dispatch_token' => str_repeat('d', 48)]);
        app(TrackAudioMetadata::class)->retry($track);
        $track->refresh();
        $this->assertSame('pending', $track->audio_metadata_status);
        $this->assertNotSame($failedJob, $track->audio_analysis_job_id);
    }

    public function test_non_audio_assignment_fails_safely(): void
    {
        $image = MediaAsset::create($this->assetData('not-audio', 'image', str_repeat('b', 64), 'png'));
        try {
            $this->track('wrong-type', $image);
            $this->fail('Non-audio playback must fail.');
        } catch (DomainException) {
            $this->assertDatabaseMissing('tracks', ['id' => 'wrong-type']);
        }
    }

    public function test_backfill_dry_run_is_read_only_and_scoped_repair_fixes_missing_or_stale_metadata(): void
    {
        $asset = $this->audio('backfill-tone', 0.75, 6000, 18000);
        $target = $this->track('backfill-target', $asset);
        $other = $this->track('backfill-other', $this->audio('other-tone', 0.5, 3000, 9000));
        $before = $target->fresh()->getAttributes();

        $this->assertSame(0, Artisan::call('cms:audio-metadata:backfill', ['--dry-run' => true, '--track' => $target->id]));
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertStringContainsString('"would_queue": 1', Artisan::output());

        $this->assertSame(0, Artisan::call('cms:audio-metadata:backfill', ['--track' => $target->id]));
        $this->assertSame('pending', app(TrackAudioMetadata::class)->status($target->fresh()));
        $this->assertNull($other->fresh()->duration, 'A scoped repair must not modify another track.');
        $this->assertStringContainsString('"queued": 1', Artisan::output());
    }

    public function test_duration_is_read_only_in_the_cms_track_form(): void
    {
        $track = $this->track('cms-read-only', $this->audio('cms-tone', 0.5, 5000, 16000));
        $user = User::create(['name' => 'Audio QA', 'email' => 'audio-qa@example.test', 'password' => bin2hex(random_bytes(20))]);
        $this->actingAs($user);

        Livewire::test(EditTrack::class, ['record' => $track->id])->assertFormFieldDisabled('duration');
    }

    private function track(string $id, MediaAsset $asset): Track
    {
        return Track::create([
            'id' => $id,
            'title' => $id,
            'audio_asset_id' => $asset->id,
            'genres' => [],
            'emotions' => [],
            'platform_overrides' => [],
            'status' => 'draft',
            'rights_scope' => 'existing_local_draft',
            'library_visible' => false,
        ]);
    }

    private function audio(string $id, float $seconds, int $quietAmplitude, int $loudAmplitude): MediaAsset
    {
        $wav = $this->wav($seconds, $quietAmplitude, $loudAmplitude);
        $sha = hash('sha256', $wav);
        $relative = "media/originals/$id/$sha.wav";
        $file = $this->fixtureRoot.DIRECTORY_SEPARATOR.$id.DIRECTORY_SEPARATOR.$sha.'.wav';
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $wav);
        return MediaAsset::create($this->assetData($id, 'audio', $sha, 'wav', $relative));
    }

    private function wav(float $seconds, int $quietAmplitude, int $loudAmplitude): string
    {
        $sampleRate = 8000;
        $sampleCount = (int) round($sampleRate * $seconds);
        $pcm = '';
        for ($index = 0; $index < $sampleCount; $index++) {
            $amplitude = $index < intdiv($sampleCount, 2) ? $quietAmplitude : $loudAmplitude;
            $sample = (int) round(sin(2 * M_PI * 440 * $index / $sampleRate) * $amplitude);
            $pcm .= pack('v', $sample < 0 ? $sample + 65536 : $sample);
        }
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        return $wav;
    }

    private function assetData(string $id, string $type, string $sha, string $extension, ?string $storagePath = null): array
    {
        return [
            'id' => $id,
            'path' => "media/cms/$id-".substr($sha, 0, 16).".$extension",
            'storage_path' => $storagePath ?? "media/originals/$id/$sha.$extension",
            'type' => $type,
            'purpose' => $type === 'audio' ? 'audio' : 'other',
            'alt' => '',
            'crop' => ['x' => 50, 'y' => 50],
            'state' => 'temporary',
            'rights_scope' => 'existing_local_draft',
            'sha256' => $sha,
        ];
    }
}
