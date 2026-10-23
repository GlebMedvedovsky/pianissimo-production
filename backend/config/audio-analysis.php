<?php

return [
    'api_token' => env('AUDIO_ANALYSIS_API_TOKEN'),
    'public_cms_url' => env('AUDIO_ANALYSIS_PUBLIC_CMS_URL'),
    'github' => [
        'repository' => env('AUDIO_ANALYSIS_GITHUB_REPOSITORY'),
        'ref' => env('AUDIO_ANALYSIS_GITHUB_REF', 'main'),
        'workflow' => env('AUDIO_ANALYSIS_GITHUB_WORKFLOW', 'pianissimo-audio-analysis.yml'),
        'dispatch_token' => env('AUDIO_ANALYSIS_GITHUB_DISPATCH_TOKEN'),
    ],
    'waveform_samples' => (int) env('CMS_AUDIO_WAVEFORM_SAMPLES', 96),
];
