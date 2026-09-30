<?php

// Local defaults remain intentionally narrow. Production is default-deny and
// only accepts explicit host and proxy values from the environment.
return \App\Support\CmsAccessPolicy::configuration(
    (string) env('APP_ENV', 'production'),
    env('CMS_ALLOWED_HOSTS'),
    env('CMS_TRUSTED_PROXIES'),
);
