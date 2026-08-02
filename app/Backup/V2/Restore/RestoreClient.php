<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

/**
 * The manager-side contract for driving the simplead-backup plugin's staged, atomic
 * RESTORE endpoints over its HMAC-signed REST namespace (simplead-backup/v1).
 *
 * Pull model: the manager materialises the final state from S3 (latest-wins + tombstones)
 * and PUSHES each chunk to the plugin's staging; the plugin never reaches out to S3.
 *
 * An interface (mirroring App\Backup\V2\Plugin\PluginClient) so RestoreRunner can be driven
 * by the real HTTP client in the lab AND by an in-process engine in deterministic tests.
 */
interface RestoreClient
{
    /**
     * POST restore/prepare — open a restore session (token) with staging areas.
     *
     * @param  array<string, mixed>  $opts  mode|scope|mirror_roots|keep_paths|db_tables|tombstones
     * @return array<string, mixed>
     */
    public function restorePrepare(string $token, array $opts): array;

    /**
     * POST restore/stage-chunk — push ONE materialised chunk (raw body) into staging.
     * $localPath is a local temp file (a file zip or a db sql.gz) already pulled from S3.
     * Idempotent + resumable (the plugin verifies sha256 and no-ops an identical re-send).
     *
     * @return array<string, mixed>
     */
    public function restoreStageChunk(string $token, string $kind, int $seq, string $localPath): array;

    /**
     * POST restore/apply — the critical, maintenance-guarded swap (DB + files), keeping
     * rollback data until commit/rollback.
     *
     * @return array<string, mixed>
     */
    public function restoreApply(string $token): array;

    /**
     * Does this host's plugin know how to detach an apply?
     *
     * Asked before the kick rather than inferred from the reply, because an older plugin does not
     * reject the unknown `async` parameter — it ignores it and applies synchronously, holding the
     * request open for minutes. The caller would then time out on what it believed was a quick
     * dispatch, and go looking for a restore to roll back while one was still running.
     */
    public function restoreSupportsAsync(): bool;

    /**
     * POST restore/apply with `async` — ask the host to detach the work and return at once.
     *
     * Answers `{async: true, method: loopback|cron}` when it could, `{async: false}` when the host
     * offers neither, in which case the caller falls back to restoreApply(). Anything that reports
     * `async: true` must then be followed with restoreStatus() until it reaches a terminal state.
     *
     * @return array<string, mixed>
     */
    public function restoreApplyAsync(string $token): array;

    /**
     * POST restore/commit — restore confirmed good: drop retained pre-apply tables + trash.
     *
     * @return array<string, mixed>
     */
    public function restoreCommit(string $token): array;

    /**
     * POST restore/rollback — return the site to its pre-apply state.
     *
     * @return array<string, mixed>
     */
    public function restoreRollback(string $token): array;

    /**
     * POST restore/status — progress / state.
     *
     * @return array<string, mixed>
     */
    public function restoreStatus(string $token): array;
}
