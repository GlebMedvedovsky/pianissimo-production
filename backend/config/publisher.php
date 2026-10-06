<?php

return [
    'api_token' => env('CMS_PUBLISHER_API_TOKEN'),
    'production_automation_enabled' => (bool) env('CMS_PRODUCTION_AUTOMATION_ENABLED', false),
    'production_timeout_minutes' => (int) env('CMS_PRODUCTION_TIMEOUT_MINUTES', 45),
    'github' => [
        'repository' => env('CMS_GITHUB_REPOSITORY'),
        'ref' => env('CMS_GITHUB_REF'),
        'workflow' => env('CMS_PRODUCTION_WORKFLOW', 'pianissimo-production-publisher.yml'),
        'preview_workflow' => env('CMS_PREVIEW_WORKFLOW', 'pianissimo-preview-builder.yml'),
        'token' => env('CMS_GITHUB_TOKEN'),
    ],
];
