<?php

return [
    // Only the public contact endpoint accepts a browser origin. Publisher
    // endpoints remain server-to-server and never receive CORS headers.
    'paths' => ['api/contact'],
    'allowed_methods' => ['POST', 'OPTIONS'],
    'allowed_origins' => [env('CONTACT_ALLOWED_ORIGIN', 'http://127.0.0.1:4321')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
