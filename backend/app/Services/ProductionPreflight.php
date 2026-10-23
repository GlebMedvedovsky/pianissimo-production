<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ProductionPreflight
{
    private const PRODUCTION_HOST = 'admin.pianissimo-production.de';

    public function run(bool $checkDatabase = false): array
    {
        $checks = [];

        $this->add($checks, config('app.env') === 'production', 'APP_ENV', 'production is required');
        $this->add($checks, config('app.debug') === false, 'APP_DEBUG', 'debug mode must be disabled');

        $url = (string) config('app.url');
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $this->add(
            $checks,
            parse_url($url, PHP_URL_SCHEME) === 'https' && $urlHost === self::PRODUCTION_HOST,
            'APP_URL',
            'must use HTTPS and the configured production CMS host',
        );

        $allowedHosts = config('dev-access.hosts', []);
        $this->add(
            $checks,
            in_array(self::PRODUCTION_HOST, $allowedHosts, true),
            'CMS_ALLOWED_HOSTS',
            'must explicitly contain the production CMS host',
        );

        $pathVariables = config('cms.production_path_variables', []);
        $explicit = config('cms.explicit_environment', []);
        foreach ($pathVariables as $variable => $key) {
            $this->add(
                $checks,
                ($explicit[$variable] ?? false) === true,
                $variable,
                'required production path variable must be explicitly configured',
            );
            $this->pathCheck(
                $checks,
                $variable,
                (string) config("cms.$key"),
                in_array($key, ['schema', 'active_manifest'], true),
                $key === 'media_incoming_root',
            );
        }

        foreach (['CMS_MEDIA_APPLICATION_MAX_KB', 'CMS_MEDIA_UPLOAD_MAX_KB', 'CMS_MEDIA_IMPORT_MAX_KB'] as $variable) {
            $this->add($checks, ($explicit[$variable] ?? false) === true, $variable, 'required production media limit must be explicitly configured');
        }
        $applicationMediaMax = (int) config('cms.media_max_kb');
        $browserMediaMax = (int) config('cms.media_upload_max_kb');
        $importMediaMax = (int) config('cms.media_import_max_kb');
        $this->add($checks, $applicationMediaMax > 0, 'Application media limit', 'must be a positive KiB value');
        $this->add($checks, $browserMediaMax > 0 && $browserMediaMax <= $applicationMediaMax, 'Browser media upload limit', 'must be positive and no greater than the application media limit');
        $this->add($checks, $importMediaMax > 0 && $importMediaMax <= $applicationMediaMax, 'SFTP media import limit', 'must be positive and no greater than the application media limit');
        $this->add(
            $checks,
            $this->livewireTemporaryUploadMaxKb() === $browserMediaMax,
            'Livewire temporary upload limit',
            'effective Livewire max rule must exactly match CMS_MEDIA_UPLOAD_MAX_KB',
        );

        $audioApiToken = (string) config('audio-analysis.api_token');
        $audioDispatchToken = (string) config('audio-analysis.github.dispatch_token');
        $this->add($checks, strlen($audioApiToken) >= 32 && strlen($audioApiToken) <= 512 && preg_match('/\s/', $audioApiToken) !== 1, 'AUDIO_ANALYSIS_API_TOKEN', 'a dedicated high-entropy API token is required');
        $this->add($checks, ! in_array($audioApiToken, [(string) config('publisher.api_token'), (string) config('publisher.content_sync_api_token')], true), 'Audio API token separation', 'must differ from publisher and content-sync tokens');
        $audioUrl = (string) config('audio-analysis.public_cms_url');
        $this->add($checks, parse_url($audioUrl, PHP_URL_SCHEME) === 'https' && strtolower((string) parse_url($audioUrl, PHP_URL_HOST)) === self::PRODUCTION_HOST, 'AUDIO_ANALYSIS_PUBLIC_CMS_URL', 'must use the production CMS HTTPS origin');
        $audioRepository = (string) config('audio-analysis.github.repository');
        $audioRef = (string) config('audio-analysis.github.ref');
        $audioWorkflow = (string) config('audio-analysis.github.workflow');
        $this->add($checks, preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $audioRepository) === 1, 'AUDIO_ANALYSIS_GITHUB_REPOSITORY', 'owner/repository is required');
        $this->add($checks, $audioRef !== '' && strlen($audioRef) <= 255 && preg_match('#^[A-Za-z0-9._/-]+$#D', $audioRef) === 1 && ! str_contains($audioRef, '..') && ! str_contains($audioRef, '//'), 'AUDIO_ANALYSIS_GITHUB_REF', 'a safe branch or tag is required');
        $this->add($checks, preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $audioWorkflow) === 1, 'AUDIO_ANALYSIS_GITHUB_WORKFLOW', 'a workflow filename is required');
        $this->add($checks, strlen($audioDispatchToken) >= 32 && strlen($audioDispatchToken) <= 512 && preg_match('/\s/', $audioDispatchToken) !== 1, 'AUDIO_ANALYSIS_GITHUB_DISPATCH_TOKEN', 'a dedicated Actions dispatch token is required');
        $this->add($checks, ! in_array($audioDispatchToken, [$audioApiToken, (string) config('publisher.github.token')], true), 'Audio dispatch token separation', 'must differ from API and publisher dispatch tokens');
        $this->add($checks, (int) config('audio-analysis.waveform_samples') === 96, 'Audio waveform samples', 'the canonical contract requires exactly 96 samples');

        $this->add($checks, PHP_VERSION_ID >= 80400, 'PHP version', 'PHP 8.4 or newer is required');
        foreach (['pdo_mysql', 'fileinfo', 'mbstring', 'openssl', 'json', 'tokenizer', 'xml', 'curl'] as $extension) {
            $this->add($checks, extension_loaded($extension), "PHP extension: $extension", 'required extension must be loaded');
        }
        $this->record(
            $checks,
            class_exists(\ZipArchive::class) ? 'PASS' : 'WARN',
            'ZipArchive',
            class_exists(\ZipArchive::class) ? 'available' : 'not available; ZIP-based workflows will require an alternative',
        );

        $connection = (string) config('database.default');
        $database = config("database.connections.$connection", []);
        $databasePresent = $connection !== ''
            && is_array($database)
            && trim((string) ($database['host'] ?? '')) !== ''
            && trim((string) ($database['port'] ?? '')) !== ''
            && trim((string) ($database['database'] ?? '')) !== ''
            && trim((string) ($database['username'] ?? '')) !== ''
            && trim((string) ($database['password'] ?? '')) !== '';
        $this->add($checks, $databasePresent, 'Database configuration', 'connection fields and a secret password must be configured');

        if ($checkDatabase) {
            try {
                DB::connection($connection)->select('SELECT 1');
                $this->record($checks, 'PASS', 'Database connectivity', 'read-only SELECT succeeded');
            } catch (\Throwable) {
                $this->record($checks, 'FAIL', 'Database connectivity', 'connection failed; details suppressed');
            }
        } else {
            $this->record($checks, 'WARN', 'Database connectivity', 'not checked; rerun with --check-database for a read-only SELECT');
        }

        foreach ($this->laravelWritablePaths() as $label => $path) {
            $exists = is_dir($path);
            $this->add($checks, $exists && is_writable($path), "$label (CLI)", $exists ? 'must be writable by the CLI user' : 'directory does not exist');
        }
        $this->record($checks, 'WARN', 'Laravel paths (web user)', 'requires FastCGI verification');

        $cmsPublic = $this->resolvedPath(public_path());
        foreach ([
            'media root' => 'media_root',
            'managed media root' => 'managed_media_root',
            'media originals root' => 'media_originals_root',
            'media incoming root' => 'media_incoming_root',
            'private media root' => 'private_media_root',
            'publication package root' => 'publication_package_root',
            'frontend apply state' => 'frontend_apply_state',
            'preview storage root' => 'preview_storage_root',
            'preview package root' => 'preview_package_root',
            'preview incoming root' => 'preview_incoming_root',
            'preview result root' => 'preview_result_root',
            'production incoming root' => 'production_incoming_root',
            'static release root' => 'static_release_root',
        ] as $label => $key) {
            $candidate = $this->resolvedPath((string) config("cms.$key"));
            $safe = $cmsPublic !== null && $candidate !== null && ! $this->inside($candidate, $cmsPublic);
            $this->add($checks, $safe, ucfirst($label).' placement', 'must be outside the CMS public document root');
        }

        $staticRoot = $this->resolvedPath((string) config('cms.static_release_root'));
        $activeSite = $this->resolvedPath((string) config('cms.public_site_root'));
        $this->add(
            $checks,
            $staticRoot !== null
                && $activeSite !== null
                && $staticRoot !== $activeSite
                && ! $this->inside($activeSite, $staticRoot)
                && ! $this->inside($staticRoot, $activeSite),
            'Static release separation',
            'release storage must not be the current active public-site directory',
        );

        $this->add(
            $checks,
            (bool) preg_match('/^[a-f0-9]{40}$/D', (string) config('cms.source_revision')),
            'CMS_SOURCE_REVISION',
            'must be a full 40-character lowercase Git SHA',
        );
        $this->add(
            $checks,
            in_array(config('cms.publication_driver'), config('cms.recognized_publication_drivers', []), true),
            'CMS_PUBLICATION_DRIVER',
            'driver must be a recognized value',
        );
        $this->add(
            $checks,
            in_array(config('cms.preview_driver'), config('cms.recognized_preview_drivers', []), true),
            'CMS_PREVIEW_DRIVER',
            'driver must be a recognized value',
        );
        if (config('cms.preview_driver') === 'package') {
            $repository = (string) config('publisher.github.repository');
            $ref = (string) config('publisher.github.ref');
            $workflow = (string) config('publisher.github.preview_workflow');
            $token = (string) config('publisher.github.token');
            $this->add($checks, preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repository) === 1, 'CMS_GITHUB_REPOSITORY', 'owner/repository is required for package previews');
            $this->add($checks, $ref !== '' && strlen($ref) <= 255 && preg_match('#^[A-Za-z0-9._/-]+$#D', $ref) === 1 && ! str_contains($ref, '..') && ! str_contains($ref, '//'), 'CMS_GITHUB_REF', 'a safe exact branch or tag is required for package previews');
            $this->add($checks, preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $workflow) === 1, 'CMS_PREVIEW_WORKFLOW', 'a workflow filename is required for package previews');
            $this->add($checks, strlen($token) >= 32 && strlen($token) <= 512 && preg_match('/\s/', $token) !== 1, 'CMS_GITHUB_TOKEN', 'an Actions:write token is required for package previews');
        }
        if (config('publisher.production_automation_enabled') === true) {
            $repository = (string) config('publisher.github.repository');
            $ref = (string) config('publisher.github.ref');
            $workflow = (string) config('publisher.github.workflow');
            $token = (string) config('publisher.github.token');
            $this->add($checks, config('cms.publication_driver') === 'package', 'CMS_PUBLICATION_DRIVER', 'package is required for automatic production publishing');
            $this->add($checks, preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repository) === 1, 'CMS_GITHUB_REPOSITORY', 'owner/repository is required for automatic production publishing');
            $this->add($checks, $ref !== '' && strlen($ref) <= 255 && preg_match('#^[A-Za-z0-9._/-]+$#D', $ref) === 1 && ! str_contains($ref, '..') && ! str_contains($ref, '//'), 'CMS_GITHUB_REF', 'a safe exact branch or tag is required for automatic production publishing');
            $this->add($checks, preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $workflow) === 1, 'CMS_PRODUCTION_WORKFLOW', 'a workflow filename is required for automatic production publishing');
            $this->add($checks, strlen($token) >= 32 && strlen($token) <= 512 && preg_match('/\s/', $token) !== 1, 'CMS_GITHUB_TOKEN', 'an Actions:write token is required for automatic production publishing');
            $timeout = (int) config('publisher.production_timeout_minutes');
            $this->add($checks, $timeout >= 5 && $timeout <= 120, 'CMS_PRODUCTION_TIMEOUT_MINUTES', 'must be between 5 and 120 minutes');
        }
        $mailDefault = config('mail.default');
        $this->add(
            $checks,
            ! in_array($mailDefault, ['log', 'array', null, ''], true),
            'MAIL_MAILER',
            'must use a real configured delivery transport; log and array mailers are not production-ready',
        );
        if ($mailDefault === 'smtp') {
            $smtpHost = config('mail.mailers.smtp.host');
            $smtpPort = config('mail.mailers.smtp.port');
            $smtpUsername = config('mail.mailers.smtp.username');
            $smtpPassword = config('mail.mailers.smtp.password');
            $smtpScheme = config('mail.mailers.smtp.scheme');

            $this->add(
                $checks,
                is_string($smtpHost) && trim($smtpHost) !== '',
                'MAIL_HOST',
                'a non-empty SMTP host is required',
            );
            $this->add(
                $checks,
                filter_var($smtpPort, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 65_535],
                ]) !== false,
                'MAIL_PORT',
                'an integer SMTP port from 1 through 65535 is required',
            );
            $this->add(
                $checks,
                is_string($smtpUsername) && trim($smtpUsername) !== '',
                'MAIL_USERNAME',
                'a non-empty SMTP username is required',
            );
            $this->add(
                $checks,
                is_string($smtpPassword) && trim($smtpPassword) !== '',
                'MAIL_PASSWORD',
                'a non-empty SMTP password is required',
            );
            $this->add(
                $checks,
                in_array($smtpScheme, ['smtp', 'smtps'], true),
                'MAIL_SCHEME',
                'must be smtp or smtps for this Laravel mail transport',
            );
        }
        $this->add(
            $checks,
            filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
            'MAIL_FROM_ADDRESS',
            'a valid server-controlled sender address is required',
        );
        $this->add(
            $checks,
            filter_var(config('contact.recipient'), FILTER_VALIDATE_EMAIL) !== false,
            'CONTACT_RECIPIENT',
            'a valid server-controlled recipient address is required',
        );
        $this->add(
            $checks,
            config('contact.allowed_origin') === 'https://pianissimo-production.de',
            'CONTACT_ALLOWED_ORIGIN',
            'must exactly match the HTTPS public Pianissimo origin',
        );
        $this->record($checks, 'PASS', 'Preflight side effects', 'no message is sent by this read-only check');

        return $checks;
    }

    private function pathCheck(array &$checks, string $variable, string $path, bool $file, bool $writable = false): void
    {
        $resolved = $this->resolvedPath($path);
        if ($resolved === null) {
            $this->record($checks, 'FAIL', "$variable path", 'must be absolute and contain no traversal');
            return;
        }

        $exists = $file ? is_file($path) && is_readable($path) : is_dir($path);
        if ($exists && $writable) $exists = is_readable($path) && is_writable($path);
        $this->add(
            $checks,
            $exists,
            "$variable path",
            $file ? 'configured file must exist and be readable' : ($writable ? 'configured directory must exist and be readable/writable' : 'configured directory must exist'),
        );
    }

    private function laravelWritablePaths(): array
    {
        return [
            'Laravel storage' => storage_path(),
            'Laravel cache' => storage_path('framework/cache'),
            'Laravel sessions' => storage_path('framework/sessions'),
            'Laravel bootstrap cache' => base_path('bootstrap/cache'),
        ];
    }

    private function livewireTemporaryUploadMaxKb(): ?int
    {
        $rules = config('livewire.temporary_file_upload.rules');
        if (is_string($rules)) $rules = explode('|', $rules);
        if (! is_array($rules)) return null;

        foreach ($rules as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/D', $rule, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private function resolvedPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return null;
        }
        if (! str_starts_with($path, '/') && ! preg_match('#^[A-Za-z]:/#', $path)) {
            return null;
        }

        $drive = preg_match('#^[A-Za-z]:/#', $path) ? strtoupper(substr($path, 0, 2)) : null;
        $pathWithoutPrefix = $drive === null ? $path : substr($path, 3);
        $parts = [];
        foreach (explode('/', $pathWithoutPrefix) as $part) {
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $prefix = $drive === null ? '/' : $drive.'/';
        $normalized = $prefix.implode('/', $parts);

        $runtimeSiteRoot = rtrim(str_replace('\\', '/', dirname(base_path(), 2)), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $normalized = strtolower($normalized);
            $runtimeSiteRoot = strtolower($runtimeSiteRoot);
        }
        if (! $this->inside($normalized, $runtimeSiteRoot) || is_link($runtimeSiteRoot)) return null;
        $resolvedRuntimeSiteRoot = realpath($runtimeSiteRoot);
        if ($resolvedRuntimeSiteRoot === false) return null;
        $resolvedRuntimeSiteRoot = rtrim(str_replace('\\', '/', $resolvedRuntimeSiteRoot), '/');
        if (PHP_OS_FAMILY === 'Windows') $resolvedRuntimeSiteRoot = strtolower($resolvedRuntimeSiteRoot);

        $relative = ltrim(substr($normalized, strlen($runtimeSiteRoot)), '/');
        $prefix = $runtimeSiteRoot;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $prefix .= '/'.$part;
            if (is_link($prefix)) return null;
        }

        $probe = $normalized;
        $suffix = [];
        while ($probe !== $runtimeSiteRoot && ! file_exists($probe)) {
            $parent = str_replace('\\', '/', dirname($probe));
            if ($parent === $probe || $parent === '.' || ! $this->inside($parent, $runtimeSiteRoot)) {
                return null;
            }
            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }
        $real = realpath($probe);
        if ($real === false) {
            return null;
        }

        $resolved = rtrim(str_replace('\\', '/', $real), '/');
        if ($suffix !== []) {
            $resolved .= '/'.implode('/', $suffix);
        }

        if (PHP_OS_FAMILY === 'Windows') $resolved = strtolower($resolved);
        return $this->inside($resolved, $resolvedRuntimeSiteRoot) ? $resolved : null;
    }

    private function inside(string $candidate, string $root): bool
    {
        $root = rtrim($root, '/');

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function add(array &$checks, bool $passed, string $label, string $detail): void
    {
        $this->record($checks, $passed ? 'PASS' : 'FAIL', $label, $passed ? 'ok' : $detail);
    }

    private function record(array &$checks, string $status, string $label, string $detail): void
    {
        $checks[] = compact('status', 'label', 'detail');
    }
}
