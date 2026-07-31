<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Models\BackupSession;
use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;

/**
 * The `backups` row that carries a V2 session into the rest of the application.
 *
 * The engine was built beside the app rather than inside it, so nothing that
 * users actually look at could see it: the site history, the overview, the
 * dashboard, the health score, the alerts, the stale-backup sweep and chain
 * retention all read `backups`, and the engine never wrote one. Rather than
 * teach five subsystems about a second table, each session opens a row here and
 * keeps it current. Everything downstream then works unchanged.
 *
 * Two details are load-bearing, and both are about BackupObserver, which fires
 * on the status *transition* rather than on the insert:
 *
 *  - the row opens as `pending` and is moved to `completed` / `failed` later, so
 *    the observer sees an edge. A row inserted directly in its final state would
 *    be silently ignored, and sites.last_backup_at would never move;
 *  - it opens when the session is created, not when it first succeeds. A session
 *    that dies in capability_check would otherwise leave no row at all, so the
 *    site would keep reporting its last good backup and nothing would alert —
 *    the exact silent-failure class the engine's own failure path closed.
 */
class BackupEnvelope
{
    /**
     * Open the row for a session that is about to start.
     *
     * `file_path` is deliberately left null. It is the object prefix, and
     * SessionLayoutResolver is the only thing allowed to decide that — computing
     * it a second time here would reintroduce exactly the drift that pinning the
     * prefix was meant to end. sync() copies it across once the resolver has
     * frozen it.
     *
     * @param  array<string, bool>  $scope
     */
    public function open(
        Site $site,
        StorageDestination $destination,
        string $type,
        string $trigger,
        array $scope,
    ): Backup {
        return Backup::create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'type' => $type,
            'trigger' => $trigger,
            'status' => BackupStatus::Pending,
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Backup queued, waiting to start...',
            'includes_database' => (bool) ($scope['database'] ?? true),
            'includes_files' => (bool) ($scope['files'] ?? true),
            'wp_version' => $site->wp_version,
            'php_version' => $site->php_version,
            'plugins_count' => $site->sitePlugins()->count(),
            'themes_count' => $site->siteThemes()->count(),
            'db_size_mb' => $site->db_size_mb,
            'started_at' => now(),
        ]);
    }

    /**
     * Bring the row in line with the session's current state.
     *
     * Called from BackupSession::transitionTo — the one funnel every state
     * change passes through — rather than from a model observer. An observer
     * would fire on every heartbeat (once per uploaded chunk) instead of the
     * eleven times a backup actually changes state, and it would never see
     * `object_prefix` at all, because SessionLayoutResolver writes that through
     * the query builder specifically to avoid firing model events.
     *
     * A no-op for sessions with no row, which keeps every existing V2 fixture
     * and test working untouched.
     */
    public function sync(BackupSession $session): void
    {
        if ($session->backup_id === null) {
            return;
        }

        $backup = Backup::find($session->backup_id);
        if (! $backup instanceof Backup) {
            return;
        }

        $state = $session->state;
        $update = [
            'status' => $this->statusFor($state),
            'stage' => $this->stageFor($state),
        ];

        $percent = $this->percentFor($state);
        if ($percent !== null) {
            $update['progress_percent'] = $percent;
        }
        $update['progress_message'] = $session->stage ?: $this->stageFor($state);

        // The prefix is frozen by the resolver on first use, which happens after
        // the row is opened. Copy it across the first time it exists.
        if ($backup->file_path === null && filled($session->object_prefix)) {
            $update['file_path'] = $session->object_prefix;
        }

        if ($state === S::Completed) {
            $update += $this->completionFields($session, $backup);
        }

        if ($state->isTerminal()) {
            $update['completed_at'] = $session->completed_at ?? now();
            $update['duration_seconds'] = $backup->started_at
                ? (int) $backup->started_at->diffInSeconds(now())
                : null;
        }

        if ($state === S::Failed || $state === S::Corrupt) {
            $update['error_message'] = $this->errorMessage($session, $state);
        }

        $backup->update($update);

        if ($state === S::Failed || $state === S::Corrupt) {
            $this->recordSiteFailure($session);
        }
    }

    /**
     * Write through the verification the V2 verifier just recorded.
     *
     * Needed as a second step because verification runs *after* the session
     * reaches `completed` — at sync() time there is nothing to copy yet. Without
     * it BackupHealthService reads every V2 backup as "not verified in the last
     * 14 days" and permanently caps those sites at 80.
     */
    public function recordVerification(BackupSession $session, bool $passed, string $message): void
    {
        if ($session->backup_id === null) {
            return;
        }

        Backup::query()->whereKey($session->backup_id)->update([
            'verified_at' => $passed ? now() : null,
            'verification_status' => $passed ? 'passed' : 'failed',
            'verification_message' => $message,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completionFields(BackupSession $session, Backup $backup): array
    {
        $fields = [
            'file_size' => $this->storedBytes($session),
            'replicas' => [],
            'is_locked' => (bool) $session->protected,
        ];

        // An incremental points at its chain base so V1's chain-aware retention
        // treats the two as one unit. Only ever a V2 base: the two engines write
        // different formats, so a chain that mixed them could not be replayed.
        $base = $session->fullBase;
        if ($base instanceof BackupSession && $base->backup_id !== null) {
            $fields['parent_backup_id'] = $base->backup_id;
        }

        return $fields;
    }

    /**
     * Total bytes this backup occupies in storage.
     *
     * The four sidecars are counted deliberately. Retention decrements a
     * destination's used_bytes by exactly this number when the backup is
     * deleted, so anything left out of the sum is never given back — a small,
     * systematic, one-directional drift in the figure quotas are enforced on.
     */
    private function storedBytes(BackupSession $session): int
    {
        $total = 0;
        foreach (($session->confirmed_objects ?? []) as $object) {
            $total += (int) ($object['size'] ?? 0);
        }

        $checkpoint = $session->checkpoint ?? [];
        $total += (int) ($checkpoint['sidecar_bytes'] ?? 0);

        return $total;
    }

    private function recordSiteFailure(BackupSession $session): void
    {
        $site = $session->site;
        if (! $site instanceof Site) {
            return;
        }

        // BackupObserver::handleFailed is suppressed for V2 rows (the engine
        // raises its own, better alert), and this bookkeeping went with it. It
        // is not optional: without it the site keeps reporting backup_ok and the
        // dashboard shows a healthy site whose backup just failed.
        $site->update(['backup_ok' => false]);
        $site->backupConfig?->update(['last_backup_status' => 'failed']);
    }

    private function errorMessage(BackupSession $session, S $state): string
    {
        $message = $session->error_message ?: 'Backup failed without a recorded reason.';

        // `corrupt` has no equivalent in BackupStatus, which the database
        // constrains to five values. Rather than widen a CHECK constraint on the
        // live backups table for one distinction, it is carried in the text —
        // the session row keeps the precise state.
        return $state === S::Corrupt ? '[corrupt] '.$message : $message;
    }

    private function statusFor(S $state): BackupStatus
    {
        return match ($state) {
            S::Requested => BackupStatus::Pending,
            // Parked, not dead: the checkpoint is live and the next attempt
            // resumes from it. Pending keeps the row non-terminal so the
            // observer does not alert on a backup that is about to continue.
            S::RetryWait => BackupStatus::Pending,
            S::Completed => BackupStatus::Completed,
            S::Cancelled => BackupStatus::Cancelled,
            S::Failed, S::Corrupt => BackupStatus::Failed,
            default => BackupStatus::InProgress,
        };
    }

    private function stageFor(S $state): string
    {
        return match ($state) {
            S::Requested => 'queued',
            S::CapabilityCheck => 'initializing',
            S::Inventory => 'inventory',
            S::DatabaseExport => 'database',
            S::FileDiff => 'file_diff',
            S::Chunking => 'chunking',
            S::UploadInitializing, S::Uploading => 'uploading',
            S::UploadVerifying => 'verifying',
            S::Finalizing => 'finalizing',
            S::Completed => 'completed',
            S::RetryWait => 'retrying',
            S::Paused => 'paused',
            S::Cancelling => 'cancelling',
            S::Cancelled => 'cancelled',
            S::Failed, S::Corrupt => 'failed',
        };
    }

    /**
     * Null means "leave the last number alone" — a session that parks or is
     * cancelled should not appear to lose the progress it made.
     */
    private function percentFor(S $state): ?int
    {
        return match ($state) {
            S::Requested => 0,
            S::CapabilityCheck => 5,
            S::Inventory => 12,
            S::DatabaseExport => 25,
            S::FileDiff => 35,
            S::Chunking => 42,
            S::UploadInitializing => 50,
            S::Uploading => 70,
            S::UploadVerifying => 92,
            S::Finalizing => 96,
            S::Completed => 100,
            default => null,
        };
    }
}
