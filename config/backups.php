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

];
