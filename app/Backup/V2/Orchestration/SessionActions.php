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
use Illuminate\Support\Facades\DB;
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
     * @param  array{scope?:array<string,mixed>,exclusions?:array<int,mixed>,resource_profile?:string,trigger?:string,full_base_id?:int|null,chain_position?:int|null,estimated_bytes?:int}  $opts
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

        // The `backups` row is opened here, before the session, and in the same
        // transaction — so a session can never exist without one. Opening it
        // lazily on first success would mean a session that dies in
        // capability_check leaves no row at all: the site would go on reporting
        // its last good backup, and nothing would alert.
        $session = DB::transaction(function () use ($site, $destination, $type, $scope, $opts) {
            $backup = $this->envelope->open(
                site: $site,
                destination: $destination,
                type: $type,
                trigger: (string) ($opts['trigger'] ?? 'manual'),
                scope: $scope,
            );

            return BackupSession::create([
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
        });

        RunBackupSessionJob::dispatch($session->id);

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
     * Pin / unpin a restore point so chain-safe retention never reclaims it.
     */
    public function setProtected(BackupSession $session, bool $protected): void
    {
        $session->protected = $protected;
        $session->save();
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
        if ($session->protected) {
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
     */
    public function startRestore(BackupSession $source, RestoreMode $mode = RestoreMode::SafeMerge): RestoreSession
    {
        $restore = RestoreSession::create([
            'site_id' => $source->site_id,
            'backup_session_id' => $source->id,
            'mode' => $mode->value,
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
     * @return array<string, bool>
     */
    private function defaultScope(string $type): array
    {
        return [
            'database' => in_array($type, ['full', 'database'], true),
            'files' => in_array($type, ['full', 'files'], true),
        ];
    }
}
