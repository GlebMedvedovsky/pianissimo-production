<?php

return [
    'allowed_origin' => env('CONTACT_ALLOWED_ORIGIN', 'http://127.0.0.1:4321'),
    'recipient' => env('CONTACT_RECIPIENT', 'sebastian@pianissimo-production.de'),
    'minimum_fill_seconds' => (int) env('CONTACT_MINIMUM_FILL_SECONDS', 2),
];
