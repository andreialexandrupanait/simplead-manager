<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * SAM_Backup_Sweep_Policy
 * =======================
 * Decides whether a restore session's leftovers may be deleted.
 *
 * The sweep used to answer this with the modification time of the session's own
 * top-level directory, which stops changing the moment the directory is created:
 * everything a running restore writes goes into subdirectories. An hour in, a
 * restore that was still moving looked abandoned, and the sweep deleted the trash
 * that held the only copy of the files it had displaced. Rollback then had
 * nothing to put back and said so to nobody.
 *
 * So the question became "what state is this session in, and how long has it been
 * silent" — with the age measured against a file the engine actually rewrites.
 * States that hold rollback data get a day; states that hold nothing get an hour.
 *
 * Pure by design: no WordPress, no filesystem. The sweep gathers the facts, this
 * decides, and a test can walk the whole table in milliseconds.
 */
final class SAM_Backup_Sweep_Policy {

    /** Nothing left to protect: the restore was confirmed, or already undone. */
    const TTL_TERMINAL = 3600;

    /**
     * Staging a large site over a slow link legitimately runs past an hour, and
     * every accepted chunk refreshes the status file — so a session that is
     * genuinely alive never gets near this.
     */
    const TTL_STAGING = 43200;

    /** A failure is evidence. Keep it around long enough to be read. */
    const TTL_FAILED = 86400;

    /**
     * These hold the trash, the journal and the retained pre-restore tables. A
     * day is not a timeout, it is a backstop for a session nobody will ever come
     * back for — and sweeping one is worth an error line either way.
     */
    const TTL_CRITICAL = 86400;

    /** No readable status: pre-0.9.0 debris, or a session that never got started. */
    const TTL_UNKNOWN = 3600;

    /**
     * @param array<string,mixed>|null $status decoded status.json, null if missing/unreadable
     */
    public static function ttl_for(?array $status): int {
        $state = is_array($status) ? (string) ($status['state'] ?? '') : '';

        switch ($state) {
            case 'committed':
            case 'rolled_back':
                return self::TTL_TERMINAL;

            case 'prepared':
            case 'staging':
            case 'staged':
                return self::TTL_STAGING;

            case 'failed':
                return self::TTL_FAILED;

            case 'applying':
            case 'applied':
                return self::TTL_CRITICAL;

            default:
                return self::TTL_UNKNOWN;
        }
    }

    /**
     * @param array<string,mixed>|null $status
     */
    public static function should_sweep(?array $status, int $age_seconds): bool {
        return $age_seconds >= self::ttl_for($status);
    }

    /**
     * Whether sweeping this session destroys the ability to undo a restore. True
     * means it should never happen while a manager is driving, so it is logged as
     * an error rather than counted.
     *
     * @param array<string,mixed>|null $status
     */
    public static function holds_rollback_data(?array $status): bool {
        $state = is_array($status) ? (string) ($status['state'] ?? '') : '';

        return $state === 'applying' || $state === 'applied' || $state === 'failed';
    }
}
