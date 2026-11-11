<?php

return [
    // Short product slug (used in logs/analytics labels).
    'slug' => env('PRODUCT_SLUG', 'starter'),

    // Detailed /health fields only with header X-Health-Token matching this value (>= 32 chars; shorter/empty = never).
    'health_token' => env('HEALTH_TOKEN'),
    'build_sha' => env('BUILD_SHA'),

    // The scheduler writes a heartbeat every minute; /health reports it stale after this many seconds.
    'scheduler_stale_after' => (int) env('SCHEDULER_STALE_AFTER', 300),

    // Hosts accepted in the Host header outside local/testing, besides APP_URL's host (comma list), e.g. the
    // internal compose alias a monitor uses: starter-nginx.
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),

    // APP_ENV=local turns off host trust and shows debug detail, so the app refuses to start as `local` when APP_URL points at a
    // public host (a production deploy copied with APP_ENV=local). Set true only if you really mean it (e.g. a LAN dev box).
    'allow_local_on_public_host' => filter_var(env('ALLOW_LOCAL_ON_PUBLIC_HOST', false), FILTER_VALIDATE_BOOLEAN),

    // Reverse proxies whose X-Forwarded-* headers are trusted: comma list of IPs/CIDRs, or * (every hop; only behind a proxy you
    // control). Empty = trust none, so request()->ip() and https detection are the direct peer's. Behind nginx/Traefik/a load
    // balancer set this, or rate limits and login lockouts see the proxy's IP for every visitor.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // Plausible: per-product site domain + script URL. Script is only rendered when both are set.
    'plausible' => [
        'domain' => env('PLAUSIBLE_DOMAIN'),
        'src' => env('PLAUSIBLE_SRC'),
    ],
];
