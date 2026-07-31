<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention dry-run (log-only) mode
    |--------------------------------------------------------------------------
    |
    | When true, RetentionService computes exactly which backup chains it would
    | delete and logs them, but performs NO storage or database deletion. This
    | is the safe-rollout guard for the P0-03 chain-aware retention fix: run
    | log-only for a week, confirm from the logs that only genuinely-expired
    | restore points are selected, then flip to false to enable real deletes.
    |
    | Defaults to TRUE (log-only) so a fresh deploy can never destroy a client
    | restore point before the new selection logic has been observed.
    |
    */
    'retention_dry_run' => env('BACKUP_RETENTION_DRY_RUN', true),

    /*
    |--------------------------------------------------------------------------
    | When a backup counts as too old
    |--------------------------------------------------------------------------
    |
    | Three screens each carried their own hardcoded 36 hours (the backups
    | overview, the dashboard counter and the per-site to-do list). Alerting on
    | the same question with a fourth copy would guarantee the alert and the UI
    | eventually disagreed about which sites are in trouble, so they all read
    | this now.
    |
    | `stale_after_hours` is a daily backup plus half a day of slack — a site
    | that missed one night. `stale_critical_after_hours` is three missed
    | nights, which is no longer a hiccup.
    |
    */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),
    'stale_critical_after_hours' => (int) env('BACKUP_STALE_CRITICAL_AFTER_HOURS', 72),

    /*
    | How long before the same stale site is worth mentioning again. The
    | NotificationService dedup window is 5 minutes, which is right for a burst
    | of events and useless for a daily sweep — without a persisted marker a
    | site with no backup would be reported every single morning until someone
    | fixed it, which is how people learn to ignore an alert channel.
    */
    'stale_realert_after_days' => (int) env('BACKUP_STALE_REALERT_AFTER_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Scheduled / bulk dispatch stagger
    |--------------------------------------------------------------------------
    |
    | When many sites are due at once we stagger the dispatch of their backup
    | jobs so a fleet-wide sweep doesn't stampede the queue and the WP hosts.
    | This is the spacing (seconds) applied per site index. The stuck-backup
    | recovery threshold below is derived from it so a late-staggered backup is
    | never flagged "stale" before it has even had a chance to start (P2-31).
    |
    */
    'stagger_interval_seconds' => (int) env('BACKUP_STAGGER_INTERVAL_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Pending stuck-backup base threshold
    |--------------------------------------------------------------------------
    |
    | Base minutes a backup may sit in the Pending state (queued but never
    | picked up) before it is treated as stuck. The effective threshold is this
    | base PLUS the stagger spread of the currently-pending cohort, so the last
    | site in a large staggered batch is not auto-retried before its own
    | scheduled start time (P2-31).
    |
    */
    'pending_stale_minutes' => (int) env('BACKUP_PENDING_STALE_MINUTES', 45),

    /*
    |--------------------------------------------------------------------------
    | Pre-update backup lock window
    |--------------------------------------------------------------------------
    |
    | Backups taken right before a plugin/theme/core update (trigger
    | `pre_update`) are locked so retention can't reclaim the rollback point
    | mid-update. Left forever, they accumulate unbounded (P2-30). After this
    | many days a pre-update lock is considered expired and the backup becomes
    | eligible for normal retention cleanup — never deleting a site's only
    | remaining restore point.
    |
    */
    'pre_update_lock_days' => (int) env('BACKUP_PRE_UPDATE_LOCK_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Direct-upload prepare polling
    |--------------------------------------------------------------------------
    |
    | The push/direct-upload pipeline waits for the WP host to build the archive
    | asynchronously. Instead of sleeping in the worker for up to an hour (which
    | wedges one of the few backup workers), the job releases itself back to the
    | queue with a delay between polls (P2-32). max_wait_seconds is the overall
    | wall-clock deadline; release_delay_seconds is the spacing between polls.
    |
    */
    'prepare_poll' => [
        'max_wait_seconds' => (int) env('BACKUP_PREPARE_MAX_WAIT_SECONDS', 3600),
        'release_delay_seconds' => (int) env('BACKUP_PREPARE_RELEASE_DELAY_SECONDS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Async restore transport (C-09)
    |--------------------------------------------------------------------------
    | When the connector advertises the `async_restore` capability, the manager
    | kicks a detached restore and polls its status instead of holding a 1800s
    | synchronous request. `enabled` is a fleet-wide KILL-SWITCH — set it false to
    | fall the whole fleet back to the synchronous path instantly if the async
    | path ever misbehaves. If a connector cannot dispatch async, the manager
    | also falls back to sync automatically.
    */
    'async_restore' => [
        'enabled' => (bool) env('ASYNC_RESTORE_ENABLED', true),
        'max_wait_seconds' => (int) env('ASYNC_RESTORE_MAX_WAIT_SECONDS', 1800),
        'poll_interval_seconds' => (int) env('ASYNC_RESTORE_POLL_INTERVAL_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Level B verification sample size
    |--------------------------------------------------------------------------
    |
    | Number of recent backups the weekly Level B verification job downloads
    | from real storage and re-runs the full integrity check against. Kept
    | config-driven so the sample can scale with fleet size without a code
    | change (P2-33). A larger sample gives stronger statistical confidence
    | that stored backups are still restorable, at the cost of more egress.
    |
    */
    'level_b_sample_size' => (int) env('BACKUP_LEVEL_B_SAMPLE_SIZE', 3),

];
