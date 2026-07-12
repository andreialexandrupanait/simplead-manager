<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SafeUpdate;
use Illuminate\Console\Command;

/**
 * P2-27: RunSafeUpdate drives a SafeUpdate row through intermediate states
 * (backing_up → updating → health_checking → rolling_back) before it reaches a
 * terminal state. A worker killed with SIGKILL never runs the job's failed()
 * hook, so a row can stay in an intermediate state forever — the UI keeps
 * polling it and the site's safe-update slot never frees. This sweeper (mirroring
 * the restore / incident / performance-test sweeps) resolves rows stuck in a
 * non-terminal state past a threshold well above the 600s job timeout to failed.
 */
class RecoverStuckSafeUpdates extends Command
{
    protected $signature = 'safe-updates:recover-stuck {--minutes=30 : Age (minutes, by last activity) after which an in-progress safe update is considered dead} {--dry-run : Show what would change without writing}';

    protected $description = 'Mark safe updates stuck in an intermediate state past a threshold as failed (P2-27)';

    /**
     * Non-terminal states a safe update passes through. Anything here older than
     * the threshold is treated as an orphan of a dead worker.
     */
    private const INTERMEDIATE_STATES = [
        'pending',
        'backing_up',
        'updating',
        'health_checking',
        'rolling_back',
    ];

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $query = SafeUpdate::query()
            ->whereIn('status', self::INTERMEDIATE_STATES)
            ->where('updated_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No stuck safe updates found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[DRY RUN] Would mark {$count} stuck safe update(s) as failed.");

            return self::SUCCESS;
        }

        $query->update([
            'status' => 'failed',
            'error_message' => 'Stuck in an intermediate state — worker died without cleanup (auto-swept).',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Marked {$count} stuck safe update(s) as failed.");

        return self::SUCCESS;
    }
}
