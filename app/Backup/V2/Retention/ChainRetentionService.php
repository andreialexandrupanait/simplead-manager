<?php

declare(strict_types=1);

namespace App\Backup\V2\Retention;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Models\Site;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Chain-safe retention for the V2 backup engine, operating on `backup_sessions` (never the V1
 * `backups` table). The atomic unit of retention is a CHAIN — a full plus every incremental that
 * descends from it — because an incremental cannot be restored without its base full and every
 * earlier increment.
 *
 * Guarantees (mirroring the V1 chain-aware RetentionService, re-expressed for V2 sessions):
 *   - a full carrying a still-in-window incremental is NEVER deleted (the whole chain is kept until
 *     its NEWEST member has expired);
 *   - the last valid full is never deleted (a site always keeps at least one restore base);
 *   - `protected` sessions (and their whole chain) are never deleted;
 *   - the last integrity-verified session (and its chain) is never deleted;
 *   - a chain is selected only when EVERY member is genuinely expired.
 *
 * SAFE ROLLOUT: DRY-RUN by default (config('backup_v2.retention_dry_run', true) → log-only, like V1
 * `retention_dry_run`). It only deletes when apply(force: true) is called AND V2 is enabled. Gated
 * by config('backup_v2.enabled') — inert in production until the owner opts in.
 *
 * Storage deletion is injected (a Closure) so this service never hard-couples to an S3 client; the
 * dispatcher wires a deleter that removes the session's object prefix. Without one, an enabled run
 * removes only the session rows (and logs), which is enough for the row-level lifecycle.
 */
final class ChainRetentionService
{
    /**
     * @param  Closure(BackupSession):void|null  $storageDeleter  removes a session's stored objects
     */
    public function __construct(
        private readonly ?Closure $storageDeleter = null,
    ) {}

    /**
     * Compute the retention plan WITHOUT deleting anything (pure selection, always safe to call).
     */
    public function plan(Site $site): RetentionPlan
    {
        return $this->buildPlan($site);
    }

    /**
     * Apply retention. Dry-run by default (log-only); pass force: true to actually delete the
     * selected sessions. Returns the same plan either way so the caller can assert selection.
     *
     * A forced run is refused unless V2 is enabled — retention must never mutate storage in a
     * production build that has not opted into V2.
     */
    public function apply(Site $site, bool $force = false): RetentionPlan
    {
        $plan = $this->buildPlan($site);

        $enabled = (bool) config('backup_v2.enabled', false);
        $dryRun = ! $force || ! $enabled;

        foreach ($plan->selectedForDeletion() as $session) {
            if ($dryRun) {
                Log::info("ChainRetentionV2[dry-run]: would delete session {$session->id}", [
                    'site_id' => $session->site_id,
                    'type' => $session->type,
                    'full_base_id' => $session->full_base_id,
                    'chain_position' => $session->chain_position,
                    'expires_at' => (string) $session->expires_at,
                ]);

                continue;
            }

            $this->deleteSession($session);
        }

        return $plan;
    }

    // ── selection ────────────────────────────────────────────────────────

    private function buildPlan(Site $site): RetentionPlan
    {
        /** @var Collection<int, BackupSession> $sessions */
        $sessions = BackupSession::query()
            ->where('site_id', $site->id)
            ->where('state', BackupSessionState::Completed->value)
            ->whereIn('type', ['full', 'incremental'])
            ->orderBy('id')
            ->get();

        $lastValidFull = $sessions
            ->where('type', 'full')
            ->sortByDesc(fn (BackupSession $s) => $s->completed_at ?? $s->created_at)
            ->first();

        $lastVerified = $sessions
            ->filter(fn (BackupSession $s): bool => $s->verified_at !== null)
            ->sortByDesc('verified_at')
            ->first();

        $delete = [];
        $keep = [];

        foreach ($this->buildChains($sessions) as $members) {
            $reason = $this->chainKeepReason($members, $lastValidFull, $lastVerified);
            if ($reason === null) {
                foreach ($members as $m) {
                    $delete[] = $m;
                }

                continue;
            }
            foreach ($members as $m) {
                $keep[] = ['session' => $m, 'reason' => $reason];
            }
        }

        return new RetentionPlan($delete, $keep);
    }

    /**
     * Group completed sessions into chains (full + descending incrementals; orphaned incrementals
     * whose base full is absent form their own chains).
     *
     * @param  Collection<int, BackupSession>  $sessions
     * @return list<list<BackupSession>>
     */
    private function buildChains(Collection $sessions): array
    {
        $fulls = $sessions->where('type', 'full');
        $incsByBase = $sessions->where('type', 'incremental')->groupBy('full_base_id');

        $chains = [];
        $fullIds = [];

        foreach ($fulls as $full) {
            $fullIds[(int) $full->id] = true;
            $members = [$full];
            if (isset($incsByBase[$full->id])) {
                foreach ($incsByBase[$full->id]->sortBy('chain_position') as $inc) {
                    $members[] = $inc;
                }
            }
            $chains[] = $members;
        }

        // Orphaned incrementals — base full missing / not completed → their own chain.
        foreach ($incsByBase as $baseId => $incs) {
            if (! isset($fullIds[(int) $baseId])) {
                $chains[] = $incs->sortBy('chain_position')->values()->all();
            }
        }

        return $chains;
    }

    /**
     * Why a chain is kept, or null when the whole chain is deletable.
     *
     * @param  list<BackupSession>  $members
     */
    private function chainKeepReason(array $members, ?BackupSession $lastValidFull, ?BackupSession $lastVerified): ?string
    {
        foreach ($members as $m) {
            if ($m->protected) {
                return 'protected';
            }
        }

        if ($lastValidFull !== null) {
            foreach ($members as $m) {
                if ((int) $m->id === (int) $lastValidFull->id) {
                    return 'last_valid_full';
                }
            }
        }

        if ($lastVerified !== null) {
            foreach ($members as $m) {
                if ((int) $m->id === (int) $lastVerified->id) {
                    return 'last_verified';
                }
            }
        }

        // Deletable only when EVERY member has genuinely expired.
        foreach ($members as $m) {
            if (! $this->isExpired($m)) {
                return 'chain_not_expired';
            }
        }

        return null;
    }

    private function isExpired(BackupSession $session): bool
    {
        return $session->expires_at !== null && $session->expires_at->isPast();
    }

    private function deleteSession(BackupSession $session): void
    {
        if ($this->storageDeleter !== null) {
            ($this->storageDeleter)($session);
        }

        Log::info("ChainRetentionV2: deleting expired session {$session->id}", [
            'site_id' => $session->site_id,
            'type' => $session->type,
            'full_base_id' => $session->full_base_id,
        ]);

        $session->delete();
    }
}
