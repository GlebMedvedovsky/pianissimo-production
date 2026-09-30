<?php

$policy = \App\Support\CmsAccessPolicy::configuration(
    (string) env('APP_ENV', 'production'),
    env('CMS_ALLOWED_HOSTS'),
    env('CMS_TRUSTED_PROXIES'),
);

return ['proxies' => $policy['trusted_proxies']];
