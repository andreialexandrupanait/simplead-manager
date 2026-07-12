<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WordPress Version Thresholds
    |--------------------------------------------------------------------------
    |
    | Versions below 'recommended' are flagged as 'high' severity.
    | Versions below 'minimum' are flagged as 'critical' severity.
    |
    */
    'wordpress' => [
        'minimum_version' => env('WP_MIN_VERSION', '6.0'),
        'recommended_version' => env('WP_RECOMMENDED_VERSION', '6.4'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSRF Guard
    |--------------------------------------------------------------------------
    |
    | Applied to user / managed-site supplied URLs that the MANAGER itself
    | fetches server-side (custom notification webhooks, on-demand SEO
    | quick-audit crawls, uptime monitor URLs). It rejects any URL that
    | resolves to a private / loopback / link-local / reserved IP range, a
    | non-http(s) scheme, or a known internal Docker service hostname.
    |
    | NOTE: this does NOT apply to the signed connector client that talks to
    | managed WordPress sites — those are public client domains handled
    | separately.
    |
    | 'ssrf_allowed_hosts' is an escape hatch: hosts listed here bypass the
    | guard entirely (e.g. an intentionally-internal integration endpoint).
    | Keep it empty unless you know exactly why you need it.
    |
    */
    'ssrf_allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SSRF_ALLOWED_HOSTS', ''))
    ))),

    'ssrf_blocked_hosts' => [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        // Internal Docker Compose service names
        'app',
        'horizon',
        'scheduler',
        'nginx',
        'pgsql',
        'pgbouncer',
        'redis',
        'mailhog',
        'gotenberg',
    ],
];
