<?php

declare(strict_types=1);

namespace App\Backup\V2\Chain;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Models\BackupConfig;
use App\Models\Site;

/**
 * Decides what tonight's backup is: a new full, or an incremental on top of
 * which chain.
 *
 * Nothing did this. `full_base_id` and `chain_position` existed on the session,
 * the resolver knew how to walk a chain, the plugin knew how to diff against a
 * base manifest — and no code ever chose. Every session started with a null base,
 * so an "incremental" either fell back to a full file sweep or, once the chain
 * resolver was asked for a base that was not there, threw.
 *
 * The schedule itself is V1's and stays V1's: `incremental_frequency` and
 * `full_backup_day_of_week` on BackupConfig are what the user configured, and
 * this reads the same two fields the old dispatcher reads. What differs is that
 * the chain is resolved from sessions rather than assumed.
 */
class ChainPlanner
{
    /** A chain is not allowed to run longer than this without a fresh full. */
    private const MAX_CHAIN_AGE_DAYS = 30;

    /**
     * @return array{type: string, full_base_id: int|null, chain_position: int|null}
     */
    public function planFor(Site $site): array
    {
        $config = $site->backupConfig;
        $base = $this->latestCompletedFull($site);

        // The first backup of a migrated site is always a full. A chain cannot
        // span engines — the two write different formats, so an incremental
        // against a V1 base could never be replayed — and there is nothing else
        // to build on.
        if ($base === null) {
            return $this->full();
        }

        if (! $config instanceof BackupConfig || ! $config->incremental_frequency) {
            return $this->full();
        }

        if ($config->full_backup_day_of_week !== null
            && now()->dayOfWeek === $config->full_backup_day_of_week) {
            return $this->full();
        }

        // A chain that has been running for a month is one long dependency: every
        // restore replays every link, and a single unreadable manifest anywhere in
        // it costs all of them. Start again.
        if ($base->completed_at !== null
            && $base->completed_at->diffInDays(now()) > self::MAX_CHAIN_AGE_DAYS) {
            return $this->full();
        }

        return [
            'type' => 'incremental',
            'full_base_id' => (int) $base->id,
            'chain_position' => $this->nextPosition((int) $base->id),
        ];
    }

    /**
     * @return array{type: string, full_base_id: null, chain_position: null}
     */
    private function full(): array
    {
        return ['type' => 'full', 'full_base_id' => null, 'chain_position' => null];
    }

    private function latestCompletedFull(Site $site): ?BackupSession
    {
        return BackupSession::query()
            ->where('site_id', $site->id)
            ->where('type', 'full')
            ->where('state', BackupSessionState::Completed->value)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Positions are contiguous from 1, and the resolver refuses a chain with a
     * gap. Derived from the highest position actually recorded rather than from a
     * count, so a deleted middle link surfaces as a broken chain at restore time
     * instead of being silently papered over by a renumbered successor.
     */
    private function nextPosition(int $baseId): int
    {
        $highest = BackupSession::query()
            ->where('full_base_id', $baseId)
            ->where('state', BackupSessionState::Completed->value)
            ->max('chain_position');

        return ((int) $highest) + 1;
    }
}
