<?php

namespace App\Support;

final class CmsConfiguration
{
    public static function make(?callable $environment = null): array
    {
        $environment ??= static fn (string $name, mixed $default = null): mixed => env($name, $default);
        $get = static fn (string $name, mixed $default = null): mixed => $environment($name, $default);

        $manifest = $get('CMS_ACTIVE_MANIFEST', $get('CMS_MANIFEST', base_path('../content/manifest.json')));

        $paths = [
            'CMS_SCHEMA' => 'schema',
            'CMS_ACTIVE_MANIFEST' => 'active_manifest',
            'CMS_MEDIA_ROOT' => 'media_root',
            'CMS_MANAGED_MEDIA_ROOT' => 'managed_media_root',
            'CMS_MEDIA_ORIGINALS_ROOT' => 'media_originals_root',
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

        $configuration = [
            'export_prefix' => 'exports',
            'manifest' => $manifest,
            'active_manifest' => $manifest,
            'schema' => $get('CMS_SCHEMA', base_path('../contracts/manifest.schema.json')),
            'media_root' => $get('CMS_MEDIA_ROOT', base_path('../frontend/public/media')),
            'managed_media_root' => $get('CMS_MANAGED_MEDIA_ROOT', base_path('../frontend/public/media/cms')),
            'frontend_apply_state' => $get('CMS_FRONTEND_APPLY_STATE', storage_path('app/private/frontend-apply')),
            'media_originals_root' => $get('CMS_MEDIA_ORIGINALS_ROOT', storage_path('app/private/media/originals')),
            'media_max_kb' => (int) $get('CMS_MEDIA_MAX_KB', 512000),
            'preview_ttl_minutes' => (int) $get('CMS_PREVIEW_TTL_MINUTES', 15),
            'preview_frontend_url' => $get('CMS_PREVIEW_FRONTEND_URL', 'http://127.0.0.1:4321'),
            'preview_frontend_internal_url' => $get('CMS_PREVIEW_FRONTEND_INTERNAL_URL', 'http://host.docker.internal:4321'),
            'preview_storage_root' => $get('CMS_PREVIEW_STORAGE_ROOT', storage_path('app/private/draft-previews')),
            // Prepared draft audio stays private until the missing project data is confirmed.
            'private_media_root' => $get('CMS_PRIVATE_MEDIA_ROOT', storage_path('app/private/04a/prepared/media')),
            'public_site_url' => $get('CMS_PUBLIC_SITE_URL', 'http://127.0.0.1:4321'),
            'source_revision' => $get('CMS_SOURCE_REVISION', ''),
            'publication_driver' => $get('CMS_PUBLICATION_DRIVER', 'local'),
            'preview_driver' => $get('CMS_PREVIEW_DRIVER', 'local-http'),
            'publication_package_root' => $get('CMS_PUBLICATION_PACKAGE_ROOT', storage_path('app/private/exports')),
            'preview_package_root' => $get('CMS_PREVIEW_PACKAGE_ROOT', storage_path('app/private/preview-packages')),
            'preview_incoming_root' => $get('CMS_PREVIEW_INCOMING_ROOT', storage_path('app/private/preview-incoming')),
            'preview_result_root' => $get('CMS_PREVIEW_RESULT_ROOT', storage_path('app/private/preview-results')),
            'production_incoming_root' => $get('CMS_PRODUCTION_INCOMING_ROOT', storage_path('app/private/production-incoming')),
            'static_release_root' => $get('CMS_STATIC_RELEASE_ROOT', dirname(base_path()).'/releases/static'),
            'public_site_root' => $get('CMS_PUBLIC_SITE_ROOT', dirname(base_path()).'/frontend/dist'),
            'recognized_publication_drivers' => ['local', 'package'],
            'recognized_preview_drivers' => ['local-http', 'package'],
            'production_path_variables' => $paths,
            'explicit_environment' => [],
        ];

        foreach ($paths as $variable => $key) {
            $configuration['explicit_environment'][$variable] = $get($variable) !== null && trim((string) $get($variable)) !== '';
        }

        return $configuration;
    }
}
