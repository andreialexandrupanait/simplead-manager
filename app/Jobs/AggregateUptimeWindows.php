<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UptimeCheck;
use App\Models\UptimeHourlyAggregate;
use App\Models\UptimeMonitor;
use App\Services\RetentionPolicyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * P3-14: recompute the long uptime window (uptime_365d) on a daily schedule
 * instead of on every single check. CheckUptime used to scan a full 365 days of
 * uptime_checks on every probe for this figure — expensive, and misleading,
 * because uptime_checks are pruned to the (configurable, default 45-day)
 * retention window, so a "365d" figure could only ever reflect the retained
 * coverage.
 *
 * This job takes that cost out of the per-check hot path. Since SPEC §14.1's hourly
 * aggregates exist, it reads them rather than the raw pings, so the "365d" label is
 * finally accurate: the aggregates are kept 13 months, the raw pings 14 days.
 */
class AggregateUptimeWindows implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'aggregate-uptime-windows';
    }

    public function handle(RetentionPolicyService $retention): void
    {
        // The figure now comes from the hourly aggregates (SPEC §14.1), which are
        // kept 13 months, so "365d" finally means 365 days. Raw pings are retained
        // for 14 days and could never have covered a year — the old bound to the
        // raw retention window made the number honest but tiny.
        $since = now()->subDays(365);

        UptimeMonitor::query()
            ->where('status', 'active')
            ->each(function (UptimeMonitor $monitor) use ($since, $retention): void {
                $stats = UptimeHourlyAggregate::query()
                    ->where('monitor_id', $monitor->id)
                    ->where('bucket_hour', '>=', $since)
                    ->selectRaw('SUM(checks_total) as total, SUM(checks_up) as up')
                    ->first();

                $total = (int) ($stats->total ?? 0);
                $up = (int) ($stats->up ?? 0);

                // Before the first hourly run there are no buckets yet; fall back to
                // the raw pings so the value never blanks out during the changeover.
                if ($total === 0) {
                    [$total, $up] = $this->fromRawChecks($monitor, $retention);
                }

                $monitor->uptime_365d = $total > 0 ? round(($up / $total) * 100, 3) : null;
                $monitor->saveQuietly();
            });
    }

    /**
     * @return array{0: int, 1: int} total and up counts over the retained raw window
     */
    private function fromRawChecks(UptimeMonitor $monitor, RetentionPolicyService $retention): array
    {
        $since = now()->subDays(min(365, $retention->getDays('uptime')));

        $stats = UptimeCheck::forMonitorSince($monitor->id, $since)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_up = true THEN 1 ELSE 0 END) as up')
            ->first();

        return [(int) ($stats->total ?? 0), (int) ($stats->up ?? 0)];
    }
}
