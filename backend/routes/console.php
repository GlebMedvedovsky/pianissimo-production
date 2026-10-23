<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\{ManifestImporter,ManifestExporter,ContentValidator};
use App\Models\Track;

Artisan::command('cms:import {--dry-run : Validate and report, roll back all database changes}', function () {
    $m=json_decode(file_get_contents(config('cms.manifest')),true,512,JSON_THROW_ON_ERROR);
    DB::beginTransaction();
    try {
        $report=app(ManifestImporter::class)->import($m);
        $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $this->option('dry-run')?DB::rollBack():DB::commit();
    } catch (Throwable $e) { DB::rollBack(); $this->error($e->getMessage()); return 1; }
    return empty($report['conflicts'])?0:2;
})->purpose('Idempotent import of the configured current manifest; never overwrite manual edits');

Artisan::command('cms:export {--dry-run : Validate without writing files} {--publish : Confirm a local publication snapshot} {--release-check : Apply production blockers; no deployment}', function () {
    try {
        if ($this->option('release-check')) app(ContentValidator::class)->validate(app(ManifestExporter::class)->package()['manifest'],true);
        if ($this->option('publish') && !$this->confirm('Nur einen lokalen Veröffentlichungsstand erstellen? Kein Deployment.', false)) return 1;
        $result=app(ManifestExporter::class)->export((bool)$this->option('dry-run'),(bool)$this->option('publish'));
        $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        return 0;
    } catch (Throwable $e) { $this->error($e->getMessage()); return 1; }
})->purpose('Validate/export an isolated content package without changing the active website');

Artisan::command('cms:admin {--reset : Reset an existing user password interactively}', function () {
    $email=trim($this->ask('Admin-E-Mail'));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->error('Ungültige E-Mail.'); return 1; }
    $user=\App\Models\User::where('email',$email)->first();
    if ($user && !$this->option('reset')) { $this->error('Benutzer existiert bereits. Für ein neues Passwort --reset verwenden.'); return 1; }
    if (!$user && $this->option('reset')) { $this->error('Benutzer nicht gefunden.'); return 1; }
    $name=$user?->name??$this->ask('Name');
    $password=$this->secret('Passwort (mindestens 12 Zeichen)');
    if (strlen($password)<12 || $password!==$this->secret('Passwort wiederholen')) { $this->error('Passwort zu kurz oder Wiederholung abweichend.'); return 1; }
    \App\Models\User::updateOrCreate(['email'=>$email],['name'=>$name,'password'=>$password]);
    $this->info('Admin gespeichert. Passwort wird nicht ausgegeben.');
    return 0;
})->purpose('Create an admin or reset their password without exposing credentials');

Artisan::command('cms:production-preflight {--check-database : Run a read-only database connectivity check}', function () {
    $this->line('Pianissimo production preflight (CLI context)');
    $checks = app(\App\Services\ProductionPreflight::class)->run((bool) $this->option('check-database'));
    foreach ($checks as $check) {
        $this->line(sprintf('%s: %s — %s', $check['status'], $check['label'], $check['detail']));
    }

    $failures = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'FAIL'));
    $warnings = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'WARN'));
    $this->line(sprintf('Result: %d FAIL, %d WARN', $failures, $warnings));

    return $failures === 0 ? 0 : 1;
})->purpose('Read-only production environment, path and runtime checks');

Artisan::command('cms:audio-metadata:backfill {--dry-run : Analyze and report without changing tracks} {--track= : Restrict to one stable track ID}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $trackId = $this->option('track');
    $query = Track::query()->whereNotNull('audio_asset_id')->orderBy('id');
    if (is_string($trackId) && trim($trackId) !== '') $query->whereKey(trim($trackId));
    $tracks = $query->get();
    if (is_string($trackId) && trim($trackId) !== '' && $tracks->isEmpty()) {
        $this->error('Track wurde nicht gefunden oder hat keine Playback-Datei.');
        return 1;
    }

    $counts = ['queued' => 0, 'would_queue' => 0, 'skipped' => 0, 'failed' => 0];
    $service = app(\App\Services\TrackAudioMetadata::class);
    $seenAssets = [];
    foreach ($tracks as $track) {
        if (! $service->needsRefresh($track)) {
            $counts['skipped']++;
            continue;
        }
        if (isset($seenAssets[$track->audio_asset_id])) {
            $counts['skipped']++;
            continue;
        }
        $seenAssets[$track->audio_asset_id] = true;
        if ($dryRun) {
            $counts['would_queue']++;
            continue;
        }
        try {
            $service->retry($track);
            $counts[$track->fresh()->audio_metadata_status === 'failed' ? 'failed' : 'queued']++;
        } catch (\Throwable) {
            $counts['failed']++;
            $this->warn("Audio-Verarbeitung konnte nicht eingeplant werden: {$track->id}");
        }
    }
    $this->line(json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $counts['failed'] === 0 ? 0 : 1;
})->purpose('Queue missing or stale audio metadata without publishing');
