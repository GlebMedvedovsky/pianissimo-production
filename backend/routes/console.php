<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\{ManifestImporter,ManifestExporter,ContentValidator};

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
