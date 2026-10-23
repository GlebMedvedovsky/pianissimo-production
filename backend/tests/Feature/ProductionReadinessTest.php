<?php

namespace Tests\Feature;

use App\Services\ProductionPreflight;
use App\Support\CmsAccessPolicy;
use App\Support\CmsConfiguration;
use App\Http\Middleware\LocalCmsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/production-preflight-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_local_defaults_remain_unchanged(): void
    {
        $configuration = CmsConfiguration::make(static fn (string $name, mixed $default = null): mixed => $default);

        $this->assertSame(base_path('../content/manifest.json'), $configuration['manifest']);
        $this->assertSame(base_path('../contracts/manifest.schema.json'), $configuration['schema']);
        $this->assertSame(base_path('../frontend/public/media'), $configuration['media_root']);
        $this->assertSame(base_path('../frontend/public/media/cms'), $configuration['managed_media_root']);
        $this->assertSame(storage_path('app/private/frontend-apply'), $configuration['frontend_apply_state']);
        $this->assertSame(storage_path('app/private/media/originals'), $configuration['media_originals_root']);
        $this->assertSame(storage_path('app/private/media-incoming'), $configuration['media_incoming_root']);
        $this->assertSame(storage_path('app/private/draft-previews'), $configuration['preview_storage_root']);
        $this->assertSame(storage_path('app/private/04a/prepared/media'), $configuration['private_media_root']);
        $this->assertSame(512000, $configuration['media_max_kb']);
        $this->assertSame(512000, $configuration['media_upload_max_kb']);
        $this->assertSame(512000, $configuration['media_import_max_kb']);
        $this->assertSame('/usr/bin/ffprobe', $configuration['audio_ffprobe_path']);
        $this->assertSame('/usr/bin/ffmpeg', $configuration['audio_ffmpeg_path']);
        $this->assertSame(96, $configuration['audio_waveform_samples']);
        $this->assertSame(15, $configuration['preview_ttl_minutes']);
        $this->assertSame('http://127.0.0.1:4321', $configuration['preview_frontend_url']);
        $this->assertSame('http://host.docker.internal:4321', $configuration['preview_frontend_internal_url']);

        $policy = CmsAccessPolicy::configuration('local', null, null);
        $this->assertSame(['172.21.0.1'], $policy['trusted_proxies']);
        $this->assertSame(['127.0.0.1', 'localhost', '1nx12grf-8080.euw.devtunnels.ms'], $policy['hosts']);
        $this->assertSame(['172.21.0.1'], config('trustedproxy.proxies'));
    }

    public function test_production_paths_are_loaded_from_environment(): void
    {
        $values = [
            'CMS_ACTIVE_MANIFEST' => '/private/config/manifest.json',
            'CMS_SCHEMA' => '/private/config/schema.json',
            'CMS_MEDIA_ROOT' => '/private/media',
            'CMS_MANAGED_MEDIA_ROOT' => '/private/media/managed',
            'CMS_MEDIA_ORIGINALS_ROOT' => '/private/media/originals',
            'CMS_MEDIA_INCOMING_ROOT' => '/private/incoming/media',
            'CMS_PRIVATE_MEDIA_ROOT' => '/private/media/prepared',
            'CMS_PUBLICATION_PACKAGE_ROOT' => '/private/packages/production',
            'CMS_FRONTEND_APPLY_STATE' => '/private/runtime/apply',
            'CMS_PREVIEW_STORAGE_ROOT' => '/private/runtime/previews',
            'CMS_PREVIEW_PACKAGE_ROOT' => '/private/packages/preview',
            'CMS_PREVIEW_INCOMING_ROOT' => '/private/incoming/preview',
            'CMS_PREVIEW_RESULT_ROOT' => '/private/runtime/preview',
            'CMS_PRODUCTION_INCOMING_ROOT' => '/private/incoming/production',
            'CMS_STATIC_RELEASE_ROOT' => '/releases/static',
            'CMS_PUBLIC_SITE_ROOT' => '/httpdocs',
        ];
        $configuration = CmsConfiguration::make(
            static fn (string $name, mixed $default = null): mixed => array_key_exists($name, $values) ? $values[$name] : $default,
        );

        foreach ($configuration['production_path_variables'] as $variable => $key) {
            $this->assertSame($values[$variable], $configuration[$key]);
            $this->assertTrue($configuration['explicit_environment'][$variable]);
        }
        $this->assertSame($configuration['active_manifest'], $configuration['manifest']);
    }

    public function test_ssh_chroot_production_path_remains_effectively_unchanged(): void
    {
        $configuration = CmsConfiguration::make(
            static fn (string $name, mixed $default = null): mixed => $name === 'CMS_SCHEMA'
                ? '/pianissimo-production.de/private/contracts/manifest.schema.json'
                : $default,
            '/pianissimo-production.de/app/backend',
        );

        $this->assertSame(
            '/pianissimo-production.de/private/contracts/manifest.schema.json',
            $configuration['schema'],
        );
    }

    public function test_fastcgi_runtime_root_replaces_the_ssh_chroot_prefix(): void
    {
        $configuration = CmsConfiguration::make(
            static fn (string $name, mixed $default = null): mixed => $name === 'CMS_STATIC_RELEASE_ROOT'
                ? '/pianissimo-production.de/releases/static'
                : $default,
            '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend',
        );

        $this->assertSame(
            '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/releases/static',
            $configuration['static_release_root'],
        );
    }

    public function test_media_transport_application_and_sftp_limits_are_separate(): void
    {
        $values = [
            'CMS_MEDIA_MAX_KB' => 64,
            'CMS_MEDIA_APPLICATION_MAX_KB' => 500,
            'CMS_MEDIA_UPLOAD_MAX_KB' => 64,
            'CMS_MEDIA_IMPORT_MAX_KB' => 400,
        ];
        $configuration = CmsConfiguration::make(
            static fn (string $name, mixed $default = null): mixed => $values[$name] ?? $default,
        );

        $this->assertSame(500, $configuration['media_max_kb']);
        $this->assertSame(64, $configuration['media_upload_max_kb']);
        $this->assertSame(400, $configuration['media_import_max_kb']);
        foreach (['CMS_MEDIA_APPLICATION_MAX_KB', 'CMS_MEDIA_UPLOAD_MAX_KB', 'CMS_MEDIA_IMPORT_MAX_KB'] as $variable) {
            $this->assertTrue($configuration['explicit_environment'][$variable]);
        }
    }

    public function test_livewire_temporary_upload_limit_matches_the_cms_browser_ceiling(): void
    {
        $expected = ['required', 'file', 'max:'.config('cms.media_upload_max_kb')];

        $this->assertSame($expected, config('livewire.temporary_file_upload.rules'));
        $this->assertSame($expected, FileUploadConfiguration::rules());
    }

    public function test_unrelated_absolute_paths_and_similar_prefixes_are_not_translated(): void
    {
        $values = [
            'CMS_SCHEMA' => '/srv/shared/contracts/manifest.schema.json',
            'CMS_ACTIVE_MANIFEST' => '/pianissimo-production.de-backup/manifest.json',
        ];
        $configuration = CmsConfiguration::make(
            static fn (string $name, mixed $default = null): mixed => $values[$name] ?? $default,
            '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend',
        );

        $this->assertSame('/srv/shared/contracts/manifest.schema.json', $configuration['schema']);
        $this->assertSame('/pianissimo-production.de-backup/manifest.json', $configuration['active_manifest']);
        $this->assertSame($configuration['active_manifest'], $configuration['manifest']);
    }

    public function test_every_production_path_variable_uses_the_runtime_translation(): void
    {
        $pathVariables = [
            'CMS_SCHEMA' => 'schema',
            'CMS_ACTIVE_MANIFEST' => 'active_manifest',
            'CMS_MEDIA_ROOT' => 'media_root',
            'CMS_MANAGED_MEDIA_ROOT' => 'managed_media_root',
            'CMS_MEDIA_ORIGINALS_ROOT' => 'media_originals_root',
            'CMS_MEDIA_INCOMING_ROOT' => 'media_incoming_root',
            'CMS_PRIVATE_MEDIA_ROOT' => 'private_media_root',
            'CMS_PUBLICATION_PACKAGE_ROOT' => 'publication_package_root',
            'CMS_FRONTEND_APPLY_STATE' => 'frontend_apply_state',
            'CMS_PREVIEW_STORAGE_ROOT' => 'preview_storage_root',
            'CMS_PREVIEW_PACKAGE_ROOT' => 'preview_package_root',
            'CMS_PREVIEW_INCOMING_ROOT' => 'preview_incoming_root',
            'CMS_PREVIEW_RESULT_ROOT' => 'preview_result_root',
            'CMS_PRODUCTION_INCOMING_ROOT' => 'production_incoming_root',
            'CMS_STATIC_RELEASE_ROOT' => 'static_release_root',
            'CMS_PUBLIC_SITE_ROOT' => 'public_site_root',
        ];
        $values = [];
        foreach ($pathVariables as $variable => $key) {
            $values[$variable] = '/pianissimo-production.de/test-paths/'.$key;
        }

        $runtimeSiteRoot = '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de';
        $configuration = CmsConfiguration::make(
            static fn (string $name, mixed $default = null): mixed => $values[$name] ?? $default,
            $runtimeSiteRoot.'/app/backend',
        );

        $this->assertSame($pathVariables, $configuration['production_path_variables']);
        foreach ($pathVariables as $variable => $key) {
            $this->assertSame($runtimeSiteRoot.'/test-paths/'.$key, $configuration[$key]);
            $this->assertTrue($configuration['explicit_environment'][$variable]);
        }
        $this->assertSame($configuration['active_manifest'], $configuration['manifest']);
    }

    public function test_production_host_is_accepted_and_unapproved_host_is_rejected(): void
    {
        config(['dev-access.hosts' => ['admin.pianissimo-production.de']]);
        $middleware = new LocalCmsAccess;

        $response = $middleware->handle(
            Request::create('https://admin.pianissimo-production.de/admin'),
            static fn (): Response => new Response('ok'),
        );
        $this->assertSame(200, $response->getStatusCode());

        try {
            $middleware->handle(
                Request::create('https://unapproved.example/admin'),
                static fn (): Response => new Response('unexpected'),
            );
            $this->fail('An unapproved host was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        }
    }

    public function test_forwarded_host_is_ignored_when_proxy_is_not_trusted(): void
    {
        config(['dev-access.hosts' => ['admin.pianissimo-production.de']]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_HOST);
        $request = Request::create(
            'https://admin.pianissimo-production.de/admin',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_X_FORWARDED_HOST' => 'unapproved.example'],
        );

        $response = (new LocalCmsAccess)->handle($request, static fn (): Response => new Response('ok'));

        $this->assertSame('admin.pianissimo-production.de', $request->getHost());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_production_proxy_defaults_are_safe_and_wildcards_are_discarded(): void
    {
        $this->assertSame([], CmsAccessPolicy::configuration('production', null, null)['trusted_proxies']);
        $this->assertSame([], CmsAccessPolicy::configuration('production', null, null)['hosts']);
        $this->assertSame([], CmsAccessPolicy::proxies('*, **, proxy.example'));
        $this->assertSame(['10.0.0.0/24', '2001:db8::1'], CmsAccessPolicy::proxies('10.0.0.0/24,2001:db8::1'));
    }

    public function test_preflight_rejects_public_or_traversing_path_placement(): void
    {
        $this->configurePreflight();
        config([
            'cms.publication_package_root' => public_path(),
            'cms.preview_incoming_root' => $this->fixtureRoot.'/../escape',
        ]);

        $checks = app(ProductionPreflight::class)->run();

        $this->assertSame('FAIL', $this->check($checks, 'Publication package root placement')['status']);
        $this->assertSame('FAIL', $this->check($checks, 'CMS_PREVIEW_INCOMING_ROOT path')['status']);
    }

    public function test_preflight_requires_safe_writable_media_incoming_root_and_consistent_limits(): void
    {
        $this->configurePreflight();
        config(['cms.media_import_max_kb' => 70000]);
        $checks = app(ProductionPreflight::class)->run();
        $this->assertSame('FAIL', $this->check($checks, 'SFTP media import limit')['status']);

        config([
            'cms.media_import_max_kb' => 65536,
            'cms.media_incoming_root' => $this->fixtureRoot.'/missing-media-incoming',
        ]);
        $checks = app(ProductionPreflight::class)->run();
        $this->assertSame('FAIL', $this->check($checks, 'CMS_MEDIA_INCOMING_ROOT path')['status']);

        config([
            'cms.media_incoming_root' => $this->fixtureRoot.'/media-incoming',
            'cms.explicit_environment.CMS_MEDIA_IMPORT_MAX_KB' => false,
        ]);
        $checks = app(ProductionPreflight::class)->run();
        $this->assertSame('FAIL', $this->check($checks, 'CMS_MEDIA_IMPORT_MAX_KB')['status']);

        config([
            'cms.explicit_environment.CMS_MEDIA_IMPORT_MAX_KB' => true,
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:12288'],
        ]);
        $checks = app(ProductionPreflight::class)->run();
        $this->assertSame('FAIL', $this->check($checks, 'Livewire temporary upload limit')['status']);
    }

    public function test_preflight_requires_separate_remote_audio_analysis_credentials(): void
    {
        $this->configurePreflight();
        $checks = app(ProductionPreflight::class)->run();
        $this->assertSame('PASS', $this->check($checks, 'AUDIO_ANALYSIS_API_TOKEN')['status']);
        $this->assertSame('PASS', $this->check($checks, 'Audio API token separation')['status']);
        $this->assertSame('PASS', $this->check($checks, 'Audio dispatch token separation')['status']);

        config(['audio-analysis.api_token' => config('publisher.api_token')]);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'Audio API token separation')['status']);
        config(['audio-analysis.api_token' => str_repeat('a', 48), 'audio-analysis.github.dispatch_token' => config('publisher.github.token')]);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'Audio dispatch token separation')['status']);
    }

    public function test_preflight_does_not_expose_secrets_or_mutate_filesystem_or_database(): void
    {
        $this->configurePreflight();
        $secret = 'never-print-this-database-secret';
        config([
            'database.connections.mysql.password' => $secret,
            'mail.mailers.smtp.password' => $secret,
        ]);
        $before = $this->treeHash($this->fixtureRoot);
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->artisan('cms:production-preflight')
            ->doesntExpectOutputToContain($secret)
            ->run();

        $this->assertSame($before, $this->treeHash($this->fixtureRoot));
        $this->assertSame(0, $queries);
    }

    public function test_invalid_source_sha_fails_preflight(): void
    {
        $this->configurePreflight();
        foreach (['short-sha', '<exact-40-character-production-commit-sha>', str_repeat('A', 40)] as $invalid) {
            config(['cms.source_revision' => $invalid]);
            $check = $this->check(app(ProductionPreflight::class)->run(), 'CMS_SOURCE_REVISION');
            $this->assertSame('FAIL', $check['status']);
        }
    }

    public function test_preflight_requires_real_contact_mail_and_exact_public_origin(): void
    {
        $this->configurePreflight();
        config(['mail.default' => 'log']);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'MAIL_MAILER')['status']);

        config([
            'mail.default' => 'smtp',
            'mail.from.address' => null,
            'contact.recipient' => 'invalid',
            'contact.allowed_origin' => 'https://example.test',
        ]);
        $checks = app(ProductionPreflight::class)->run();
        $this->assertSame('FAIL', $this->check($checks, 'MAIL_FROM_ADDRESS')['status']);
        $this->assertSame('FAIL', $this->check($checks, 'CONTACT_RECIPIENT')['status']);
        $this->assertSame('FAIL', $this->check($checks, 'CONTACT_ALLOWED_ORIGIN')['status']);

        config([
            'mail.from.address' => 'website@pianissimo-production.de',
            'contact.recipient' => 'sebastian@pianissimo-production.de',
            'contact.allowed_origin' => 'https://pianissimo-production.de',
        ]);
        $checks = app(ProductionPreflight::class)->run();
        foreach (['MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'CONTACT_RECIPIENT', 'CONTACT_ALLOWED_ORIGIN'] as $label) {
            $this->assertSame('PASS', $this->check($checks, $label)['status']);
        }
    }

    public function test_preflight_requires_complete_smtp_configuration(): void
    {
        $this->configurePreflight();

        config(['mail.mailers.smtp.host' => '']);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'MAIL_HOST')['status']);
        config(['mail.mailers.smtp.host' => 'smtp.example.test']);

        foreach ([null, 0, 65_536, 'not-a-port'] as $invalidPort) {
            config(['mail.mailers.smtp.port' => $invalidPort]);
            $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'MAIL_PORT')['status']);
        }
        config(['mail.mailers.smtp.port' => 587]);

        config(['mail.mailers.smtp.username' => '']);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'MAIL_USERNAME')['status']);
        config(['mail.mailers.smtp.username' => 'website-user']);

        config(['mail.mailers.smtp.password' => '']);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'MAIL_PASSWORD')['status']);
        config(['mail.mailers.smtp.password' => 'test-password-not-a-real-secret']);

        config(['mail.mailers.smtp.scheme' => 'tls']);
        $this->assertSame('FAIL', $this->check(app(ProductionPreflight::class)->run(), 'MAIL_SCHEME')['status']);
        config(['mail.mailers.smtp.scheme' => 'smtp']);

        $checks = app(ProductionPreflight::class)->run();
        foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_SCHEME', 'MAIL_FROM_ADDRESS'] as $label) {
            $this->assertSame('PASS', $this->check($checks, $label)['status']);
        }
    }

    private function configurePreflight(): void
    {
        foreach (['schema.json', 'manifest.json'] as $file) {
            file_put_contents($this->fixtureRoot.'/'.$file, '{}');
        }
        foreach (['media', 'private-media', 'originals', 'media-incoming', 'packages', 'apply', 'preview-storage', 'preview-packages', 'preview-incoming', 'preview-results', 'production-incoming', 'static-releases', 'active-site'] as $directory) {
            File::ensureDirectoryExists($this->fixtureRoot.'/'.$directory);
        }

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://admin.pianissimo-production.de',
            'dev-access.hosts' => ['admin.pianissimo-production.de'],
            'cms.schema' => $this->fixtureRoot.'/schema.json',
            'cms.active_manifest' => $this->fixtureRoot.'/manifest.json',
            'cms.manifest' => $this->fixtureRoot.'/manifest.json',
            'cms.media_root' => $this->fixtureRoot.'/media',
            'cms.managed_media_root' => $this->fixtureRoot.'/media',
            'cms.media_originals_root' => $this->fixtureRoot.'/originals',
            'cms.media_incoming_root' => $this->fixtureRoot.'/media-incoming',
            'cms.media_max_kb' => 65536,
            'cms.media_upload_max_kb' => 65536,
            'cms.media_import_max_kb' => 65536,
            'audio-analysis.api_token' => str_repeat('a', 48),
            'audio-analysis.public_cms_url' => 'https://admin.pianissimo-production.de',
            'audio-analysis.github.repository' => 'pianissimo/audio-worker',
            'audio-analysis.github.ref' => 'main',
            'audio-analysis.github.workflow' => 'pianissimo-audio-analysis.yml',
            'audio-analysis.github.dispatch_token' => str_repeat('d', 48),
            'audio-analysis.waveform_samples' => 96,
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:65536'],
            'cms.private_media_root' => $this->fixtureRoot.'/private-media',
            'cms.publication_package_root' => $this->fixtureRoot.'/packages',
            'cms.frontend_apply_state' => $this->fixtureRoot.'/apply',
            'cms.preview_storage_root' => $this->fixtureRoot.'/preview-storage',
            'cms.preview_package_root' => $this->fixtureRoot.'/preview-packages',
            'cms.preview_incoming_root' => $this->fixtureRoot.'/preview-incoming',
            'cms.preview_result_root' => $this->fixtureRoot.'/preview-results',
            'cms.production_incoming_root' => $this->fixtureRoot.'/production-incoming',
            'cms.static_release_root' => $this->fixtureRoot.'/static-releases',
            'cms.public_site_root' => $this->fixtureRoot.'/active-site',
            'cms.source_revision' => str_repeat('a', 40),
            'cms.publication_driver' => 'package',
            'cms.preview_driver' => 'package',
            'publisher.github.repository' => 'pianissimo/production',
            'publisher.github.ref' => 'main',
            'publisher.github.preview_workflow' => 'pianissimo-preview-builder.yml',
            'publisher.github.token' => str_repeat('g', 40),
            'cms.explicit_environment' => array_fill_keys([
                ...array_keys(config('cms.production_path_variables')),
                'CMS_MEDIA_APPLICATION_MAX_KB', 'CMS_MEDIA_UPLOAD_MAX_KB', 'CMS_MEDIA_IMPORT_MAX_KB',
            ], true),
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'website-user',
            'mail.mailers.smtp.password' => 'test-password-not-a-real-secret',
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.from.address' => 'website@pianissimo-production.de',
            'contact.recipient' => 'sebastian@pianissimo-production.de',
            'contact.allowed_origin' => 'https://pianissimo-production.de',
        ]);
    }

    private function check(array $checks, string $label): array
    {
        foreach ($checks as $check) {
            if ($check['label'] === $label) {
                return $check;
            }
        }

        $this->fail("Missing preflight check: $label");
    }

    private function treeHash(string $root): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $entries[$relative] = $entry->isFile() ? hash_file('sha256', $entry->getPathname()) : 'directory';
        }
        ksort($entries);

        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
