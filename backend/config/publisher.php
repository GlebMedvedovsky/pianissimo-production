<?php

return [
    'api_token' => env('CMS_PUBLISHER_API_TOKEN'),
    'github' => [
        'repository' => env('CMS_GITHUB_REPOSITORY'),
        'ref' => env('CMS_GITHUB_REF'),
        'workflow' => env('CMS_PRODUCTION_WORKFLOW', 'pianissimo-production-publisher.yml'),
        'preview_workflow' => env('CMS_PREVIEW_WORKFLOW', 'pianissimo-preview-builder.yml'),
        'token' => env('CMS_GITHUB_TOKEN'),
    ],
];
