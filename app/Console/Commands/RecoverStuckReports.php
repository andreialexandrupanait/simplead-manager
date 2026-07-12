<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Console\Command;

/**
 * P2-05: GenerateReport drives a Report row into the 'generating' state before
 * producing the PDF. A worker killed with SIGKILL (e.g. an over-budget Gotenberg
 * render that blows the job timeout) never runs the job's failed() hook, so the
 * row stays 'generating' forever and the UI shows it as perpetually in progress.
 * This sweeper (mirroring the restore / safe-update / performance-test sweeps)
 * flips reports stuck in 'generating' past a threshold — well above the job's
 * timeout × tries envelope — to 'failed' with a reason.
 */
class RecoverStuckReports extends Command
{
    protected $signature = 'reports:recover-stuck {--minutes=45 : Age (minutes, by last activity) after which a generating report is considered dead} {--dry-run : Show what would change without writing}';

    protected $description = 'Mark reports stuck in the generating state past a threshold as failed (P2-05)';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $query = Report::query()
            ->where('status', ReportStatus::Generating->value)
            ->where('updated_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No stuck reports found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[DRY RUN] Would mark {$count} stuck report(s) as failed.");

            return self::SUCCESS;
        }

        $query->update([
            'status' => ReportStatus::Failed->value,
            'error_message' => 'Stuck in generating state — worker died without cleanup (auto-swept).',
            'updated_at' => now(),
        ]);

        $this->info("Marked {$count} stuck report(s) as failed.");

        return self::SUCCESS;
    }
}
