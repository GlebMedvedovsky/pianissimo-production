<?php
// Local-only access policy. Recheck the exact Docker gateway if its network is recreated.
return [
    'trusted_proxies' => ['172.21.0.1'],
    'hosts' => ['127.0.0.1', 'localhost', '1nx12grf-8080.euw.devtunnels.ms'],
];
