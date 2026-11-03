<?php

use App\Support\TrustedProxies;

/*
| Which machines may tell the application, in the X-Forwarded-* headers, who
| the visitor is and whether they came over HTTPS (Laravel's own TrustProxies
| middleware reads this key). Set TRUSTED_PROXIES in .env when this sits behind
| nginx or a load balancer; see docs/deployment-fa.md.
|
| It lives in a config file, not in bootstrap/app.php, because env() only sees
| .env while the config is not cached, and production caches it.
*/
return [
    'proxies' => TrustedProxies::fromEnv(env('TRUSTED_PROXIES')),
];
