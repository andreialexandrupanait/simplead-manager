<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backup Engine V2 (simplead-backup) — feature flags & tuning
|--------------------------------------------------------------------------
|
| Isolated config for the NEW backup engine (`simplead-backup` plugin +
| Laravel orchestration). Kept in its own file so V2 has zero blast radius on
| the live V1 engine (`config/backups.php`).
|
| SAFETY CONTRACT: every enable flag defaults to FALSE. With defaults, V2 is
| completely inert in production — no scheduler, no queues, no restore, no
| reconciliation writes, no legacy restore. V2 only ever runs in the local
| lab / staging or on explicitly whitelisted site ids, and never touches
| production storage or client sites until the owner says "DA PILOT BACKUP V2".
|
| Never call env() outside config files — read these via config('backup_v2.*').
|
*/

return [

    // Master kill-switch. When false, no V2 code path may run against a site.
    'enabled' => (bool) env('BACKUP_ENGINE_V2_ENABLED', false),

    // Comma-separated site ids allowed to use V2 (empty = none). Even with
    // 'enabled' true, a site must be listed here to be eligible.
    'site_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BACKUP_ENGINE_V2_SITE_IDS', ''))
    ), static fn ($v) => $v !== '')),

    // V2 restore orchestration. False = no V2 restore can be initiated.
    'restore_enabled' => (bool) env('BACKUP_ENGINE_V2_RESTORE_ENABLED', false),

    // Weekly proven-restore into sandbox using the V2 engine.
    'proven_restore_enabled' => (bool) env('BACKUP_ENGINE_V2_PROVEN_RESTORE_ENABLED', false),

    // Restoring LEGACY (v2/v3) backups through the new reader. Read/index is
    // always allowed; actually restoring a legacy artifact is gated here.
    'legacy_restore_enabled' => (bool) env('BACKUP_LEGACY_RESTORE_ENABLED', false),

    // backup:reconcile-storage WRITE mode. False = command is strictly read-only
    // (reports drift only); it never mutates rows or storage.
    'reconciliation_writes_enabled' => (bool) env('BACKUP_RECONCILIATION_WRITES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Resource profiles (LOW_IMPACT / NORMAL / FAST)
    |--------------------------------------------------------------------------
    | How hard a backup is allowed to lean on a client's site. Read by
    | App\Backup\V2\Orchestration\ResourceProfile, which maps each value onto a
    | parameter the plugin honours or a decision the manager makes.
    */
    'profiles' => [
        // Every value here reaches a parameter the plugin actually honours, or a
        // decision the manager actually makes. The keys that could not — a memory
        // budget the plugin overrides with its own ini_set, a file-count batch it
        // has no parameter for — were removed rather than left describing an
        // intention the code never carried out.
        'low_impact' => [
            'file_chunk_mb' => 25,      // smaller zips = shorter single requests
            'compression' => 'store',   // no CPU spent compressing already-compressed media
            'db_time_budget' => 20,     // seconds per dump call, well under any proxy timeout
            'db_segment_mb' => 8,
            'db_batch_rows' => 200,     // rows per SELECT — the lever on peak memory
            'pause_ms' => 400,          // breathing room between chunks
            'min_free_disk_mb' => 512,
            'max_concurrency' => 1,
        ],
        'normal' => [
            'file_chunk_mb' => 50,
            'compression' => 'store',
            'db_time_budget' => 45,
            'db_segment_mb' => 16,
            'db_batch_rows' => 500,
            'pause_ms' => 100,
            'min_free_disk_mb' => 512,
            'max_concurrency' => 1,
        ],
        'fast' => [ // VPS/dedicated only, explicit opt-in
            'file_chunk_mb' => 100,
            'compression' => 'deflate',
            'db_time_budget' => 90,
            'db_segment_mb' => 32,
            'db_batch_rows' => 1000,
            'pause_ms' => 0,
            'min_free_disk_mb' => 1024,
            'max_concurrency' => 2,
        ],
    ],
    'default_profile' => (string) env('BACKUP_ENGINE_V2_DEFAULT_PROFILE', 'low_impact'),

    /*
    |--------------------------------------------------------------------------
    | Segment / chunk format (chosen by benchmark in P2 — see DECISION-LOG.md)
    |--------------------------------------------------------------------------
    | Placeholder defaults; the real format + part size are locked by measured
    | comparison (object-per-file vs pack/TAR-stream vs multipart-per-segment).
    */
    'multipart_part_mb' => (int) env('BACKUP_ENGINE_V2_MULTIPART_PART_MB', 16),
    'presigned_ttl_seconds' => (int) env('BACKUP_ENGINE_V2_PRESIGNED_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Downloadable package
    |--------------------------------------------------------------------------
    | One archive per restore point, built on the manager straight after a backup
    | completes — it reads only from storage, so it never touches the client's site.
    |
    | Packages do NOT deduplicate against each other the way the engine's own
    | objects do: each is a whole separate copy of the site. Keeping one per site
    | is the difference between a fixed cost and a second bucket that grows as
    | fast as the first. Older restore points can still be packaged on demand.
    */
    'portable' => [
        'keep_per_site' => (int) env('BACKUP_ENGINE_V2_PORTABLE_KEEP', 1),
    ],

    // Where work that does not fit in memory gets staged.
    //
    // NOT sys_get_temp_dir(). In production /tmp is a 512 MB tmpfs — RAM with a
    // filesystem in front of it — so "stage it on disk instead" would have moved
    // a 473 MB package from one memory limit to another, and a site of any real
    // size would fill it. The storage volume is actual disk with hundreds of
    // gigabytes behind it, and it is the only writable path in an otherwise
    // read-only container.
    'work_dir' => (string) env('BACKUP_ENGINE_V2_WORK_DIR', storage_path('app/backup-v2')),

    // Per-part retry policy for HardenedMultipartUploader (exponential backoff +
    // full jitter). A failed PART is retried in place; the whole object is never
    // restarted. See App\Backup\V2\Storage\HardenedMultipartUploader.
    'multipart_max_attempts' => (int) env('BACKUP_ENGINE_V2_MULTIPART_MAX_ATTEMPTS', 5),
    'multipart_retry_base_ms' => (int) env('BACKUP_ENGINE_V2_MULTIPART_RETRY_BASE_MS', 200),
    'multipart_retry_max_ms' => (int) env('BACKUP_ENGINE_V2_MULTIPART_RETRY_MAX_MS', 15000),

    // Retries for READING objects back (verification, deep verify, portable
    // package, restore, manifest reads) — S3ClientFactory::readClient(). The SDK
    // retries throttling, 5xx and network failures with its own exponential
    // backoff, and does not retry a genuinely missing or forbidden object. Left at
    // 0 this cost us two of the first five production backups: a Hetzner
    // `SlowDown` while re-reading checksums.json branded a correct backup
    // unverified, and one failed GetObject discarded twenty minutes of finished
    // work. 4 attempts = the original plus three.
    'read_max_attempts' => (int) env('BACKUP_ENGINE_V2_READ_MAX_ATTEMPTS', 4),

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    | Objects are sealed with AES-256-GCM on the manager, under a key held per
    | client, before they reach the bucket. The manager already has every chunk
    | in a local temp file on its way out, so this costs no plugin change and no
    | extra round trip.
    |
    | The cost is real and worth stating: the key is stored under APP_KEY, so
    | APP_KEY becomes the root of the scheme. `backup:export-keys` produces the
    | recovery bundle that makes that survivable. Turning this OFF does not make
    | existing backups readable without a key — each object records its own key
    | id, and restore honours it.
    */
    'encryption' => [
        'enabled' => (bool) env('BACKUP_ENGINE_V2_ENCRYPTION_ENABLED', true),
    ],

    // Manifest / completion contract. The 'require_manifest' and
    // 'require_completion_marker' switches that used to sit here described a
    // contract the code enforces unconditionally in finalize() — a backup cannot
    // reach `completed` without both. A flag that cannot be turned off is not a
    // setting, it is a claim that the reader can weaken the guarantee.
    // Bumped to /2 when the manifest started carrying the COMPLETE file list for the restore point
    // rather than only what that run uploaded. A /2 manifest names the object holding every file, so
    // one read restores or packages it; a /1 incremental only makes sense replayed over its chain.
    // ChainResolver keeps the replay path for /1 backups, which stay valid until retention takes them.
    'format_version' => 'simplead-backup/2',

    // S3 object layout root (per TARGET-ARCHITECTURE.md). Tenant-isolated.
    'object_prefix' => 'clients/{client_id}/sites/{site_id}/backups/{backup_id}',

    /*
    |--------------------------------------------------------------------------
    | simplead-backup plugin HMAC client (lab defaults)
    |--------------------------------------------------------------------------
    | Credentials the Laravel orchestrator (App\Backup\V2\Plugin\SimpleadBackupClient)
    | uses to sign requests to the WP plugin. In PRODUCTION the key/secret and base
    | URL come per-site (decrypted from the Site row) — these config values are only
    | the lab fallback (the plugin's own options are set to the connector's spike
    | creds). Inert in prod: nothing signs a request unless a V2 backup runs.
    */
    'plugin' => [
        'key' => (string) env('BACKUP_ENGINE_V2_PLUGIN_KEY', 'spikekey12345'),
        'secret' => (string) env('BACKUP_ENGINE_V2_PLUGIN_SECRET', 'spikesecret67890'),
        'timeout' => (int) env('BACKUP_ENGINE_V2_PLUGIN_TIMEOUT', 120),
        // DB dump soft time budget + gzip segment target handed to the plugin.
        'db_time_budget' => (int) env('BACKUP_ENGINE_V2_DB_TIME_BUDGET', 90),
        // The ceiling the dump budget may escalate to when a database does not finish in the
        // profile's allowance. Under Cloudflare's 100-second origin timeout on purpose: past that
        // the site is still dumping and the answer we get is a 524 that explains nothing.
        'db_time_budget_max' => (int) env('BACKUP_ENGINE_V2_DB_TIME_BUDGET_MAX', 90),
        'db_segment_bytes' => (int) env('BACKUP_ENGINE_V2_DB_SEGMENT_BYTES', 8 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lab S3 (MinIO) destination — used ONLY by the local lab tests/harness.
    |--------------------------------------------------------------------------
    | In production the S3 target is resolved from the site's StorageDestination
    | (decrypted exactly like App\Services\...\S3Driver) — see the TODO in
    | App\Backup\V2\Storage\S3ClientFactory. These values point at the spike MinIO.
    */
    'lab_s3' => [
        'endpoint' => (string) env('BACKUP_ENGINE_V2_LAB_S3_ENDPOINT', 'http://spike-minio:9000'),
        'region' => (string) env('BACKUP_ENGINE_V2_LAB_S3_REGION', 'us-east-1'),
        'key' => (string) env('BACKUP_ENGINE_V2_LAB_S3_KEY', 'spikeadmin'),
        'secret' => (string) env('BACKUP_ENGINE_V2_LAB_S3_SECRET', 'spikeadmin123'),
        'bucket' => (string) env('BACKUP_ENGINE_V2_LAB_S3_BUCKET', 'backups'),
        'use_path_style_endpoint' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Manager (P5) — Livewire V2 backup console gating
    |--------------------------------------------------------------------------
    | The V2 manager UI (App\Livewire\Backup\V2\*) lives under its OWN route
    | prefix (/backup-v2) behind this flag AND an admin role check. With the
    | default FALSE, the routes 404 for everyone and the console is invisible in
    | production — the V1 UI is the only backup UI users can reach. It defaults
    | to the master 'enabled' flag so turning V2 on also exposes its console,
    | while still allowing the UI to be dark-launched independently.
    */
    'ui_enabled' => (bool) env(
        'BACKUP_ENGINE_V2_UI_ENABLED',
        (bool) env('BACKUP_ENGINE_V2_ENABLED', false),
    ),

    /*
    |--------------------------------------------------------------------------
    | Storage quotas (P5)
    |--------------------------------------------------------------------------
    | Enforced on a StorageDestination's RECONCILED used_bytes (the truth job in
    | App\Backup\V2\Jobs\ReconcileUsedBytesJob). A new backup that would push a
    | destination past its quota_bytes is refused with a clear message.
    |
    | SAFETY: enforcement is gated. With 'enforce' FALSE the QuotaService still
    | REPORTS usage (the UI shows it) but never blocks a backup — so a production
    | build that has not opted into V2 is never affected. warn_threshold_percent
    | drives the "storage limit reached" alert.
    */
    'quota' => [
        'enforce' => (bool) env(
            'BACKUP_ENGINE_V2_QUOTA_ENFORCE',
            (bool) env('BACKUP_ENGINE_V2_ENABLED', false),
        ),
        'warn_threshold_percent' => (int) env('BACKUP_ENGINE_V2_QUOTA_WARN_PERCENT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts (P5)
    |--------------------------------------------------------------------------
    | Backup-success + storage-limit-reached mail. Reuses the app mail stack;
    | recipients fall back to the app "from" address when none are configured.
    | Gated so nothing is ever sent unless V2 is enabled.
    */
    'notifications' => [
        'enabled' => (bool) env(
            'BACKUP_ENGINE_V2_NOTIFICATIONS_ENABLED',
            (bool) env('BACKUP_ENGINE_V2_ENABLED', false),
        ),
        'notify_on_success' => (bool) env('BACKUP_ENGINE_V2_NOTIFY_ON_SUCCESS', true),
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BACKUP_ENGINE_V2_ALERT_RECIPIENTS', ''))
        ), static fn ($v) => $v !== '')),
    ],
];
