<?php
return [
    'export_prefix' => 'exports',
    'manifest' => env('CMS_MANIFEST', base_path('../content/manifest.json')),
    'schema' => env('CMS_SCHEMA', base_path('../contracts/manifest.schema.json')),
    'media_root' => env('CMS_MEDIA_ROOT', base_path('../frontend/public/media')),
    // Prepared draft audio stays private until the missing project data is confirmed.
    'private_media_root' => storage_path('app/private/04a/prepared/media'),
];
