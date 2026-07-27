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

    // The new scheduler that dispatches V2 backups. False = V2 never self-starts.
    'scheduler_enabled' => (bool) env('BACKUP_ENGINE_V2_SCHEDULER_ENABLED', false),

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
    | Starting values only — the WORDPRESS-ENGINE / PERFORMANCE work derives the
    | real limits from host capability discovery (max_execution_time,
    | memory_limit, free disk, load) in the lab. These are conservative seeds so
    | nothing runs unbounded before benchmarking sets them.
    */
    'profiles' => [
        'low_impact' => [
            'step_seconds' => 8,    // wall-clock budget per WP step (well under CF ~100s)
            'memory_budget_mb' => 64,   // soft cap; suspend if the host memory_limit is tighter
            'min_free_disk_mb' => 512,  // disk guard floor
            'file_batch' => 200,  // files processed per step
            'pause_ms' => 400,  // adaptive pause between steps
            'max_concurrency' => 1,
        ],
        'normal' => [
            'step_seconds' => 20,
            'memory_budget_mb' => 128,
            'min_free_disk_mb' => 512,
            'file_batch' => 1000,
            'pause_ms' => 100,
            'max_concurrency' => 2,
        ],
        'fast' => [ // VPS/dedicated only, explicit opt-in
            'step_seconds' => 45,
            'memory_budget_mb' => 256,
            'min_free_disk_mb' => 1024,
            'file_batch' => 4000,
            'pause_ms' => 0,
            'max_concurrency' => 4,
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
    'file_chunk_target_mb' => (int) env('BACKUP_ENGINE_V2_FILE_CHUNK_MB', 100),
    'db_chunk_target_mb' => (int) env('BACKUP_ENGINE_V2_DB_CHUNK_MB', 50),
    'multipart_part_mb' => (int) env('BACKUP_ENGINE_V2_MULTIPART_PART_MB', 16),
    'presigned_ttl_seconds' => (int) env('BACKUP_ENGINE_V2_PRESIGNED_TTL', 600),

    // Per-part retry policy for HardenedMultipartUploader (exponential backoff +
    // full jitter). A failed PART is retried in place; the whole object is never
    // restarted. See App\Backup\V2\Storage\HardenedMultipartUploader.
    'multipart_max_attempts' => (int) env('BACKUP_ENGINE_V2_MULTIPART_MAX_ATTEMPTS', 5),
    'multipart_retry_base_ms' => (int) env('BACKUP_ENGINE_V2_MULTIPART_RETRY_BASE_MS', 200),
    'multipart_retry_max_ms' => (int) env('BACKUP_ENGINE_V2_MULTIPART_RETRY_MAX_MS', 15000),

    // Manifest / completion contract
    'format_version' => 'simplead-backup/1',
    'require_manifest' => true,      // a backup cannot be 'completed' without a valid manifest
    'require_completion_marker' => true, // _COMPLETE written last, always

    // S3 object layout root (per TARGET-ARCHITECTURE.md). Tenant-isolated.
    'object_prefix' => 'clients/{client_id}/sites/{site_id}/backups/{backup_id}',
];
