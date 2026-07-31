<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plugin Abandonment
    |--------------------------------------------------------------------------
    */
    'plugin_abandonment_years' => env('PLUGIN_ABANDONMENT_YEARS', 2),

    /*
    |--------------------------------------------------------------------------
    | Database Health Thresholds
    |--------------------------------------------------------------------------
    */
    'db_total_size_warning' => env('DB_SIZE_WARNING_BYTES', 1_073_741_824),     // 1 GB
    'db_autoload_size_warning' => env('DB_AUTOLOAD_WARNING_BYTES', 1_048_576),  // 1 MB
    'db_overhead_warning' => env('DB_OVERHEAD_WARNING_BYTES', 104_857_600),     // 100 MB
    'db_table_size_warning' => env('DB_TABLE_SIZE_WARNING_BYTES', 524_288_000), // 500 MB

    /*
    |--------------------------------------------------------------------------
    | Security Scan
    |--------------------------------------------------------------------------
    */
    'security_score_critical_threshold' => env('SECURITY_CRITICAL_THRESHOLD', 50),

    /*
    |--------------------------------------------------------------------------
    | Backup Retention
    |--------------------------------------------------------------------------
    */
    'max_import_records' => env('MAX_IMPORT_RECORDS', 500),

    /*
    |--------------------------------------------------------------------------
    | SSL / TLS certificate-expiry monitoring (P2-08)
    |--------------------------------------------------------------------------
    | How often a monitor's certificate expiry is re-checked, and the default
    | warning window (in days) used when a monitor has no per-monitor override.
    */
    'ssl_check_interval_hours' => env('SSL_CHECK_INTERVAL_HOURS', 24),
    'ssl_expiry_warning_days' => env('SSL_EXPIRY_WARNING_DAYS', 14),
    'ssl_connect_timeout_seconds' => env('SSL_CONNECT_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | External heartbeat (dead-man's switch)
    |--------------------------------------------------------------------------
    | The scheduler pings this URL every minute. If the scheduler (or the whole
    | app) stops, the external monitor — e.g. healthchecks.io — fires an alert
    | out-of-band, since the platform cannot be trusted to report its own death.
    | Leave null to disable.
    */
    'heartbeat_url' => env('SCHEDULER_HEARTBEAT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Repeated job-failure alerting (P3-33)
    |--------------------------------------------------------------------------
    | Fire a critical alert once a given job class fails this many times within
    | the rolling window (seconds). The counter is atomic (Cache::increment) and
    | the alert fires once per window when the threshold is reached or exceeded.
    */
    'job_failure_alert_threshold' => (int) env('JOB_FAILURE_ALERT_THRESHOLD', 3),
    'job_failure_window_seconds' => (int) env('JOB_FAILURE_WINDOW_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Alert-storm aggregation (C-11)
    |--------------------------------------------------------------------------
    | When many sites go down (or recover) at once — a shared cause like the
    | monitoring host, an upstream network, or Cloudflare — sending one message
    | per site per channel floods every channel. With this on, site_down and
    | site_recovered notifications are routed through the same per-channel buffer
    | as info notifications, so ProcessNotificationBatch coalesces a burst into a
    | single "Nx" message per channel (one for down, one for recovery). The cost
    | is a short coalescing delay (up to the batch cadence, ~60s) on these two
    | events; set to false to restore immediate per-site delivery.
    */
    'aggregate_alert_storms' => (bool) env('ALERT_STORM_AGGREGATION', true),

    /*
    |--------------------------------------------------------------------------
    | Second observation point (SPEC §5.1)
    |--------------------------------------------------------------------------
    | "Două eșecuri consecutive înainte de a deschide un incident, ideal din două
    | locații. Altfel o sincopă de rețea pe VPS declanșează downtime fals pe toată
    | flota."
    |
    | Every check we run leaves from the same Hetzner box, so a hiccup on its
    | uplink is indistinguishable from the whole fleet going down. `url` points at
    | the Cloudflare Worker in workers/uptime-probe — a genuinely different network
    | path — which answers only "can YOU reach this?".
    |
    | Unset = no second opinion, and the engine falls back to the consecutive-
    | failure rule alone. A second location that cannot be reached NEVER suppresses
    | an incident: silence from the probe is not evidence the site is fine.
    */
    'second_location' => [
        'url' => env('UPTIME_SECOND_LOCATION_URL'),
        'secret' => env('UPTIME_SECOND_LOCATION_SECRET'),
        'timeout' => (int) env('UPTIME_SECOND_LOCATION_TIMEOUT', 12),
    ],

    /*
    | Consecutive failures required before an incident is OPENED (as opposed to
    | alerted on, which stays with the monitor's own alert_after_failures). The
    | spec's floor is two; a monitor asking for more is honoured.
    */
    'incident_after_failures' => (int) env('UPTIME_INCIDENT_AFTER_FAILURES', 2),

    /*
    |--------------------------------------------------------------------------
    | Contact-form test (SPEC §5.4)
    |--------------------------------------------------------------------------
    | "Trimitere reală săptămânală cu marcaj, plus verificare de livrare."
    |
    | The submission is a standard one: a fixed identity, with the word TEST in
    | every field that takes free text, so anyone who later finds the entry or the
    | email knows immediately what it is.
    |
    | `deliver_to` is where the site's notification is REDIRECTED. That redirect is
    | what makes delivery verifiable at all — the connector used to abort the send,
    | which left no message to look for. It also keeps the client's own inbox clean.
    | A site can override it (sites.form_test_email) when its forms must be tested
    | against a different mailbox.
    |
    | Unset = the test still runs and still confirms suppression, but the connector
    | falls back to aborting the mail, and delivery cannot be verified.
    */
    'form_test' => [
        'enabled' => (bool) env('FORM_TEST_ENABLED', true),
        'deliver_to' => env('FORM_TEST_DELIVER_TO'),
        'name' => env('FORM_TEST_NAME', 'SimpleAD TEST'),
        // Shared secret for the inbound hook the test mailbox forwards to. Unset =
        // the endpoint rejects everything, so delivery is simply never confirmed.
        'webhook_secret' => env('FORM_TEST_WEBHOOK_SECRET'),
        // Connector release that redirects instead of aborting the notification.
        'min_connector_version' => '2.21.0',
    ],
];
