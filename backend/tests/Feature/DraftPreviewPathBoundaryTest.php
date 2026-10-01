<?php

namespace Tests\Feature;

use App\Services\{DraftPreviewPackageService, DraftPreviewResultService};
use DomainException;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class DraftPreviewPathBoundaryTest extends TestCase
{
    public function test_runtime_site_root_is_derived_for_ssh_and_fastcgi_paths_in_both_services(): void
    {
        $cases = [
            '/pianissimo-production.de/app/backend' => '/pianissimo-production.de',
            '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend'
                => '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de',
        ];

        foreach ([DraftPreviewPackageService::class, DraftPreviewResultService::class] as $serviceClass) {
            $method = new ReflectionMethod($serviceClass, 'runtimeSiteRoot');
            foreach ($cases as $basePath => $expected) {
                $this->assertSame($expected, $method->invoke(app($serviceClass), $basePath));
            }
        }
    }

    public function test_roots_outside_runtime_site_root_are_rejected_by_both_services(): void
    {
        $runtimeSiteRoot = dirname(base_path(), 2);
        $outside = dirname($runtimeSiteRoot).DIRECTORY_SEPARATOR.'draft-preview-outside-'.bin2hex(random_bytes(6));

        config(['cms.preview_package_root' => $outside.DIRECTORY_SEPARATOR.'packages']);
        $this->assertPrivateCallThrows(
            DraftPreviewPackageService::class,
            'root',
            [],
            'Der Vorschau-Paketpfad ist ungültig.',
        );

        config([
            'cms.preview_incoming_root' => $outside.DIRECTORY_SEPARATOR.'incoming',
            'cms.preview_result_root' => $outside.DIRECTORY_SEPARATOR.'results',
        ]);
        foreach (['preview_incoming_root', 'preview_result_root'] as $key) {
            $this->assertPrivateCallThrows(
                DraftPreviewResultService::class,
                'privateRoot',
                [$key],
                'Ein privater Vorschau-Pfad ist ungültig.',
            );
        }

        $this->assertDirectoryDoesNotExist($outside);
    }

    public function test_symlink_below_runtime_site_root_is_rejected_by_both_services(): void
    {
        $fixture = storage_path('framework/testing/draft-preview-path-boundary-'.bin2hex(random_bytes(6)));
        $target = $fixture.DIRECTORY_SEPARATOR.'target';
        $link = $fixture.DIRECTORY_SEPARATOR.'link';
        File::ensureDirectoryExists($target, 0750);

        if (! symlink($target, $link)) {
            File::deleteDirectory($fixture);
            $this->markTestSkipped('The test environment cannot create symbolic links.');
        }

        try {
            config(['cms.preview_package_root' => $link.DIRECTORY_SEPARATOR.'packages']);
            $this->assertPrivateCallThrows(
                DraftPreviewPackageService::class,
                'root',
                [],
                'Der Vorschau-Paketpfad ist nicht verfügbar.',
            );

            config([
                'cms.preview_incoming_root' => $link.DIRECTORY_SEPARATOR.'incoming',
                'cms.preview_result_root' => $link.DIRECTORY_SEPARATOR.'results',
            ]);
            foreach (['preview_incoming_root', 'preview_result_root'] as $key) {
                $this->assertPrivateCallThrows(
                    DraftPreviewResultService::class,
                    'privateRoot',
                    [$key],
                    'Ein privater Vorschau-Pfad ist nicht verfügbar.',
                );
            }
        } finally {
            if (is_link($link)) unlink($link);
            File::deleteDirectory($fixture);
        }
    }

    public function test_fastcgi_symlink_probe_never_inspects_ancestors_above_runtime_site_root(): void
    {
        $runtimeRoot = '/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de';
        $configured = $runtimeRoot.'/private/runtime/preview-results';

        foreach ([DraftPreviewPackageService::class, DraftPreviewResultService::class] as $serviceClass) {
            $probes = [];
            $method = new ReflectionMethod($serviceClass, 'containsSymlinkComponent');
            $containsLink = $method->invoke(
                app($serviceClass),
                $configured,
                $runtimeRoot,
                function (string $path) use (&$probes): bool {
                    $probes[] = $path;
                    return false;
                },
            );

            $this->assertFalse($containsLink);
            $this->assertSame($runtimeRoot, $probes[0]);
            $this->assertNotContains('/var', $probes);
            $this->assertNotContains('/var/www', $probes);
            $this->assertNotContains('/var/www/vhosts', $probes);
            foreach ($probes as $probe) $this->assertTrue($probe === $runtimeRoot || str_starts_with($probe, $runtimeRoot.'/'));
        }
    }

    private function assertPrivateCallThrows(string $serviceClass, string $methodName, array $arguments, string $message): void
    {
        try {
            (new ReflectionMethod($serviceClass, $methodName))->invokeArgs(app($serviceClass), $arguments);
            $this->fail($serviceClass.'::'.$methodName.' should have rejected the path.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
