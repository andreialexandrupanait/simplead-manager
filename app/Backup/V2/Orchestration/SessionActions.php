<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreMode;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Jobs\RunBackupSessionJob;
use App\Backup\V2\Jobs\RunRestoreSessionJob;
use App\Backup\V2\Jobs\VerifyBackupSessionJob;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Quota\QuotaService;
use App\Backup\V2\StateMachine\BackupStateMachine;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\RetentionService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The action surface the V2 console (P5 Livewire components) calls to drive backup
 * / restore sessions — one testable seam that fronts the runners' queued jobs and
 * the FSM transitions.
 *
 * SAFETY: dispatching a job is inert in production — RunBackupSessionJob /
 * RunRestoreSessionJob both hard-refuse to run unless the matching config
 * ('backup_v2.enabled' / '.restore_enabled') is on. The UI may still create /
 * list / prepare sessions with the flags off; nothing executes against a site.
 *
 * Quota is enforced here on the destination's reconciled used_bytes BEFORE a
 * session is created (QuotaExceededException with a clear message).
 */
class SessionActions
{
    public function __construct(
        private readonly QuotaService $quota = new QuotaService,
        private readonly BackupEnvelope $envelope = new BackupEnvelope,
    ) {}

    /**
     * Start (queue) a new backup. $type ∈ full|incremental|database|files.
     *
     * @param  array{scope?:array<string,mixed>,exclusions?:array<int,mixed>,resource_profile?:string,trigger?:string,delay_seconds?:int,full_base_id?:int|null,chain_position?:int|null,estimated_bytes?:int,sync?:bool,held_lock_token?:string|null}  $opts
     */
    public function startBackup(Site $site, string $type, array $opts = []): BackupSession
    {
        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            throw new RuntimeException('No storage destination is configured for this site.');
        }

        // Enforce quota on the reconciled used_bytes (no-op unless enforcement is on).
        $this->quota->assertWithinQuota($destination, (int) ($opts['estimated_bytes'] ?? 0));

        $scope = $opts['scope'] ?? $this->defaultScope($type);

        // What this site has said it never wants backed up.
        //
        // The columns existed and nothing read them: exclude_paths and
        // exclude_tables were on backup_configs, absent from the model's fillable
        // and casts, and used by no row on the fleet. The plugin has had a full
        // exclusion engine the whole time — folders, globs, extensions, size and
        // age bounds, include-or-exclude with deterministic precedence — reached
        // through `scope['rules']`. Nothing ever put anything there.
        //
        // Per-run exclusions still win: a caller that passes its own scope has
        // already decided.
        $config = $site->backupConfig;
        if (! isset($opts['scope']) && $config !== null) {
            $paths = array_values(array_filter((array) ($config->exclude_paths ?? [])));
            $tables = array_values(array_filter((array) ($config->exclude_tables ?? [])));

            if ($paths !== []) {
                $scope['rules'] = $paths;
            }
            if ($tables !== []) {
                $scope['exclude_tables'] = $tables;
            }
        }

        // The `backups` row is opened BEFORE the session, so a session can never
        // exist without one. Opening it lazily on first success would mean a
        // session that dies in capability_check leaves no row at all: the site
        // would go on reporting its last good backup, and nothing would alert.
        //
        // Deliberately NOT wrapped in a transaction. Production runs PostgreSQL
        // behind PgBouncer in transaction-pooling mode, where an explicit
        // transaction and prepared statements do not mix — the first statement
        // inside aborts and every one after it fails with "current transaction
        // is aborted". Ordering gives the guarantee anyway, and the failure this
        // trades for is benign in a way the transaction's was not: an orphaned
        // `pending` row with no session is swept by stuck recovery, whereas a
        // session with no row is invisible to everything the operator looks at.
        $backup = $this->envelope->open(
            site: $site,
            destination: $destination,
            type: $type,
            trigger: (string) ($opts['trigger'] ?? 'manual'),
            scope: $scope,
        );

        try {
            $session = BackupSession::create([
                'site_id' => $site->id,
                'backup_id' => $backup->id,
                'type' => $type,
                'scope' => $scope,
                'exclusions' => $opts['exclusions'] ?? [],
                'resource_profile' => $opts['resource_profile'] ?? (string) config('backup_v2.default_profile', 'low_impact'),
                'state' => BackupSessionState::Requested,
                'confirmed_objects' => [],
                'confirmed_parts' => [],
                'idempotency_key' => 'ui-'.$type.'-'.$site->id.'-'.Str::random(16),
                'format_version' => (string) config('backup_v2.format_version', 'simplead-backup/1'),
                'full_base_id' => $opts['full_base_id'] ?? null,
                'chain_position' => $opts['chain_position'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Take the row back rather than leaving a backup that will never run
            // sitting `pending` in the site's history.
            $backup->delete();

            throw $e;
        }

        $heldLockToken = isset($opts['held_lock_token']) ? (string) $opts['held_lock_token'] : null;

        // A safe update cannot queue this: it holds the site's operation lock for
        // the whole update and refuses to change the site unless the rollback
        // point completed. Queueing would hand the work to a worker that then
        // waits for the lock the caller is holding — the caller waiting on
        // itself. So it runs inline, borrowing that lock.
        if ((bool) ($opts['sync'] ?? false)) {
            RunBackupSessionJob::dispatchSync($session->id, $heldLockToken);

            return $session;
        }

        $pending = RunBackupSessionJob::dispatch($session->id, $heldLockToken);

        // The scheduler spreads a night's backups so twenty sites are not pulled
        // at once. The row already exists and reads `pending`, so the delay is
        // visible as "queued" rather than as nothing happening.
        $delay = (int) ($opts['delay_seconds'] ?? 0);
        if ($delay > 0) {
            $pending->delay(now()->addSeconds($delay));
        }

        return $session;
    }

    /**
     * Resume a checkpointed session (retry_wait / paused) by re-queuing its runner.
     */
    public function resume(BackupSession $session): void
    {
        $this->redispatch($session);
    }

    /**
     * Retry a stalled/failed attempt. A checkpointed session resumes; nothing is
     * re-run for an already-completed one.
     */
    public function retry(BackupSession $session): void
    {
        if ($session->state === BackupSessionState::Completed) {
            return;
        }

        $this->redispatch($session);
    }

    /**
     * Request a pause (legal only from an in-flight processing state).
     */
    public function pause(BackupSession $session): void
    {
        if (BackupStateMachine::canTransition($session->state, BackupSessionState::Paused)) {
            $session->transitionTo(BackupSessionState::Paused, 'paused by operator');
        }
    }

    /**
     * Request cancellation (moves to cancelling; the runner finalises to cancelled).
     */
    public function cancel(BackupSession $session): void
    {
        if (BackupStateMachine::canTransition($session->state, BackupSessionState::Cancelling)) {
            $session->transitionTo(BackupSessionState::Cancelling, 'cancel requested by operator');
        }
    }

    /**
     * Pin / unpin a restore point so nothing reclaims it.
     *
     * "Protected" and "locked" were two flags on two tables, and neither knew
     * about the other. Retention reads `backups.is_locked` and nothing else, so a
     * restore point protected here was still swept up by the nightly pass —
     * somebody pinned a backup and the system deleted it anyway. In the other
     * direction, a backup locked from the site's Backups page was reported as
     * unprotected by the console, and SessionActions::delete() would remove it.
     *
     * One concept, one flag, and it is the row's: `is_locked` is what retention
     * has always consulted, and what the person clicking the padlock is looking
     * at. The session column is kept in step so anything still reading it sees
     * the truth rather than a stale second opinion.
     */
    public function setProtected(BackupSession $session, bool $protected): void
    {
        $session->protected = $protected;
        $session->save();

        $backup = $session->backup;
        if ($backup instanceof Backup) {
            $backup->update([
                'is_locked' => $protected,
                'lock_reason' => $protected ? 'manual' : null,
            ]);
        }
    }

    /**
     * Queue a real integrity verification.
     *
     * This used to be markVerified(), which set verified_at = now() and checked
     * nothing — a button that made a claim on the operator's behalf. The claim
     * is load-bearing: BackupHealthService scores a site on verified_at, so a
     * decorative stamp reports a site as protected by a backup nobody checked.
     *
     * Nothing here writes verified_at. Only {@see BackupVerifier} does, in both
     * directions, after actually looking at the objects.
     */
    public function requestVerification(BackupSession $session): void
    {
        VerifyBackupSessionJob::dispatch($session->id);
    }

    /**
     * Delete a backup session and the objects it wrote. Refuses to orphan a
     * chain: a completed full that still carries completed incrementals cannot
     * be deleted.
     *
     * The objects part is not incidental. This used to delete the row and
     * nothing else, so every deletion from the console silently left a full
     * backup's worth of chunks in the bucket, paid for indefinitely with nothing
     * pointing at them. Routed through RetentionService::purge, which is the one
     * place that knows how to remove a backup from storage — including replicas
     * and the used_bytes accounting — rather than a second, quieter deleter.
     */
    public function delete(BackupSession $session): void
    {
        // The row's flag as well as the session's — see setProtected(). A backup
        // locked from the site's Backups page used to be invisible to this check,
        // so the padlock protected it from retention and from nothing else.
        $row = $session->backup;
        $locked = $row instanceof Backup && $row->is_locked;

        if ($session->protected || $locked) {
            throw new RuntimeException('Cannot delete a protected backup. Unprotect it first.');
        }

        $hasChildren = $session->chainMembers()
            ->where('state', BackupSessionState::Completed->value)
            ->exists();
        if ($session->type === 'full' && $hasChildren) {
            throw new RuntimeException('Cannot delete a full backup that still has incrementals. Delete the incrementals first.');
        }

        $backup = $session->backup;
        $session->delete();

        if ($backup instanceof Backup) {
            app(RetentionService::class)->purge($backup);
        }
    }

    /**
     * Start (queue) a restore of the given backup session.
     *
     * @param  array<string, mixed>|null  $scope  database|files|paths — null restores everything.
     *                                            Carried so the site page's selective restore means
     *                                            the same thing on this engine as it does on the old
     *                                            one; the plan bounds MIRROR deletions to these
     *                                            paths too, so a selective restore cannot reach
     *                                            outside what was actually chosen.
     */
    public function startRestore(BackupSession $source, RestoreMode $mode = RestoreMode::SafeMerge, ?array $scope = null): RestoreSession
    {
        $restore = RestoreSession::create([
            'site_id' => $source->site_id,
            'backup_session_id' => $source->id,
            'mode' => $mode->value,
            'scope' => $scope,
            'state' => RestoreSessionState::Requested,
            'idempotency_key' => 'ui-restore-'.$source->id.'-'.Str::random(16),
        ]);

        RunRestoreSessionJob::dispatch($restore->id);

        return $restore;
    }

    private function redispatch(BackupSession $session): void
    {
        RunBackupSessionJob::dispatch($session->id);
    }

    /**
     * Every restore point holds files AND the database.
     *
     * `incremental` matched neither list, so the scope came out
     * {database: false, files: false} and an incremental produced a backup
     * containing nothing at all — which then failed verification for listing no
     * objects. The type describes how the FILES are captured (a full sweep, or
     * only what changed since the chain base); the database is dumped whole and
     * transactionally every time, because a point you can restore to is a point
     * where the files and the data agree.
     *
     * @return array<string, bool>
     */
    private function defaultScope(string $type): array
    {
        return [
            'database' => in_array($type, ['full', 'incremental', 'database'], true),
            'files' => in_array($type, ['full', 'incremental', 'files'], true),
        ];
    }
}
