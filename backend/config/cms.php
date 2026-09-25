<?php
return [
    'export_prefix' => 'exports',
    'manifest' => env('CMS_MANIFEST', base_path('../content/manifest.json')),
    'schema' => env('CMS_SCHEMA', base_path('../contracts/manifest.schema.json')),
    'media_root' => env('CMS_MEDIA_ROOT', base_path('../frontend/public/media')),
    'managed_media_root' => env('CMS_MANAGED_MEDIA_ROOT', base_path('../frontend/public/media/cms')),
    'frontend_apply_state' => env('CMS_FRONTEND_APPLY_STATE', storage_path('app/private/frontend-apply')),
    'media_originals_root' => storage_path('app/private/media/originals'),
    'media_max_kb' => (int) env('CMS_MEDIA_MAX_KB', 512000),
    // Prepared draft audio stays private until the missing project data is confirmed.
    'private_media_root' => storage_path('app/private/04a/prepared/media'),
];
