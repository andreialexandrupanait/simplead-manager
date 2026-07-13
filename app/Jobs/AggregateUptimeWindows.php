<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UptimeCheck;
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
 * This job bounds the query to the actual retained coverage so the stored value
 * is honest (it reflects the data that genuinely exists), and takes the cost out
 * of the per-check hot path.
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
        // uptime_checks only exist for the retained window, so the long-window
        // figure can only ever cover that. Bound the query to it (capped at a
        // year) so the value is honest rather than mislabelled.
        $days = min(365, $retention->getDays('uptime'));
        $since = now()->subDays($days);

        UptimeMonitor::query()
            ->where('status', 'active')
            ->each(function (UptimeMonitor $monitor) use ($since): void {
                $stats = UptimeCheck::forMonitorSince($monitor->id, $since)
                    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_up = true THEN 1 ELSE 0 END) as up')
                    ->first();

                $total = (int) ($stats->total ?? 0);
                $up = (int) ($stats->up ?? 0);

                $monitor->uptime_365d = $total > 0 ? round(($up / $total) * 100, 3) : null;
                $monitor->saveQuietly();
            });
    }
}
