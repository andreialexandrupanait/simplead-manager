<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AggregateUptimeHourly;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill of uptime_hourly_aggregates from the raw pings still on disk.
 *
 * This has to be run BEFORE the raw retention window is shortened to the 14 days
 * SPEC §14.1 asks for. The scheduled AggregateUptimeHourly only re-folds the last
 * 48 hours, so without a backfill everything between day 15 and the old 45-day
 * window would be pruned having never been aggregated — a month of history gone
 * with no way to recover it.
 */
class BackfillUptimeHourly extends Command
{
    protected $signature = 'uptime:backfill-hourly {--days=60 : How far back to fold}';

    protected $description = 'Fold existing raw uptime pings into hourly aggregates (run before shortening raw retention)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $pings = DB::table('uptime_checks')->where('checked_at', '>=', $since)->count();

        if ($pings === 0) {
            $this->warn("No uptime checks in the last {$days} days — nothing to fold.");

            return self::SUCCESS;
        }

        $this->info("Folding {$pings} pings from the last {$days} days...");

        (new AggregateUptimeHourly($since))->handle();

        $buckets = DB::table('uptime_hourly_aggregates')
            ->where('bucket_hour', '>=', $since)
            ->count();

        $this->info("Done. {$buckets} hourly buckets now cover that window.");
        $this->line('Raw retention can now be shortened to 14 days without losing history.');

        return self::SUCCESS;
    }
}
