<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PerformanceTest;
use Illuminate\Console\Command;

/**
 * P1-09: RunPerformanceTest creates 'running' rows before calling PageSpeed. A
 * worker killed with SIGKILL never runs the job's failed() hook, so those rows
 * stay 'running' forever — the performance UI keeps polling them and the row is
 * never resolved. This sweeper marks rows stuck in 'running' past a threshold
 * (well above the 300s job timeout) as failed.
 */
class RecoverStuckPerformanceTests extends Command
{
    protected $signature = 'performance:recover-stuck-tests {--minutes=15 : Age (minutes) after which a running test is considered dead} {--dry-run : Show what would change without writing}';

    protected $description = 'Mark performance tests stuck in "running" past a threshold as failed (P1-09)';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $query = PerformanceTest::query()
            ->where('status', 'running')
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No stuck performance tests found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[DRY RUN] Would mark {$count} stuck performance test(s) as failed.");

            return self::SUCCESS;
        }

        $query->update([
            'status' => 'failed',
            'error_message' => 'Orphaned running test — worker died without cleanup (auto-swept).',
            'updated_at' => now(),
        ]);

        $this->info("Marked {$count} stuck performance test(s) as failed.");

        return self::SUCCESS;
    }
}
