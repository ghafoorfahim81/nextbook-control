<?php

namespace App\Support;

/**
 * Reads TRUSTED_PROXIES: which machines may tell the application, in the
 * X-Forwarded-* headers, who the visitor really is and whether they came over
 * HTTPS.
 *
 * Behind nginx or a load balancer this must be set, or the application sees
 * every visitor as the proxy itself (so the sign-in throttle and the audit
 * trail's addresses are useless) and thinks the site is plain HTTP (so the
 * links and redirects it makes are http). It must also be narrow: trusting
 * everyone lets anyone claim to be anyone with a header, so `*` is only for a
 * server that can be reached through the proxy alone.
 */
class TrustedProxies
{
    /** @return array<int, string>|string|null  null (trust none), '*' (trust all), or a list of addresses */
    public static function fromEnv(?string $value): array|string|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));

        return $list === [] ? null : $list;
    }
}
