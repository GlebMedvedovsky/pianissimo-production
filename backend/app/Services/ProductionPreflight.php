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
            $this->pathCheck($checks, $variable, (string) config("cms.$key"), in_array($key, ['schema', 'active_manifest'], true));
        }

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
        $this->add($checks, config('mail.default') === 'log', 'MAIL_MAILER', 'must remain log');
        $this->record($checks, 'PASS', 'External side effects', 'no SMTP, analytics or tracking action is performed');

        return $checks;
    }

    private function pathCheck(array &$checks, string $variable, string $path, bool $file): void
    {
        $resolved = $this->resolvedPath($path);
        if ($resolved === null) {
            $this->record($checks, 'FAIL', "$variable path", 'must be absolute and contain no traversal');
            return;
        }

        $exists = $file ? is_file($path) && is_readable($path) : is_dir($path);
        $this->add(
            $checks,
            $exists,
            "$variable path",
            $file ? 'configured file must exist and be readable' : 'configured directory must exist',
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

        $probe = $normalized;
        $suffix = [];
        while (! file_exists($probe)) {
            $parent = str_replace('\\', '/', dirname($probe));
            if ($parent === $probe || $parent === '.') {
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

        return PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
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
