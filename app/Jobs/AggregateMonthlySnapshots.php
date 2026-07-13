<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SiteMonthlySnapshot;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateMonthlySnapshots implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [300, 900];

    public function __construct(
        public ?int $year = null,
        public ?int $month = null,
    ) {
        // Default to previous month
        if (! $this->year || ! $this->month) {
            $lastMonth = now()->subMonth();
            $this->year = $lastMonth->year;
            $this->month = $lastMonth->month;
        }
    }

    public function handle(): void
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        Log::info("Aggregating monthly snapshots for {$this->year}-{$this->month}");

        // Each section is isolated: one section failing (e.g. a stale query or a
        // Cloudflare API error) must not abort the whole snapshot build and leave
        // every other column unpopulated, as a single crash previously did.
        $this->runStep('uptime', fn () => $this->aggregateUptime($start, $end));
        $this->runStep('backups', fn () => $this->aggregateBackups($start, $end));
        $this->runStep('updates', fn () => $this->aggregateUpdates($start, $end));
        $this->runStep('security', fn () => $this->aggregateSecurity($start, $end));
        $this->runStep('performance', fn () => $this->aggregatePerformance($start, $end));
        $this->runStep('analytics', fn () => $this->aggregateAnalytics());
        $this->runStep('search_console', fn () => $this->aggregateSearchConsole());
        $this->runStep('cloudflare', fn () => $this->aggregateCloudflare($start, $end));
        $this->runStep('incidents', fn () => $this->aggregateIncidents($start, $end));

        Log::info("Monthly snapshot aggregation complete for {$this->year}-{$this->month}");
    }

    private function runStep(string $name, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::warning("Monthly snapshot aggregation step '{$name}' failed for {$this->year}-{$this->month}: {$e->getMessage()}");
        }
    }

    private function aggregateUptime(Carbon $start, Carbon $end): void
    {
        $rows = DB::select('
            SELECT
                um.site_id,
                AVG(uc.response_time) as avg_response_ms,
                CASE WHEN COUNT(*) > 0
                    THEN ROUND(SUM(CASE WHEN uc.is_up THEN 1 ELSE 0 END)::numeric / COUNT(*) * 100, 3)
                    ELSE NULL
                END as uptime_pct,
                SUM(CASE WHEN NOT uc.is_up THEN 1 ELSE 0 END) as down_checks
            FROM uptime_checks uc
            JOIN uptime_monitors um ON um.id = uc.monitor_id
            WHERE uc.checked_at BETWEEN ? AND ?
            GROUP BY um.site_id
        ', [$start, $end]);

        foreach ($rows as $row) {
            $this->upsert($row->site_id, [
                'uptime_avg_response_ms' => $row->avg_response_ms,
                'uptime_percentage' => $row->uptime_pct,
                'uptime_down_checks' => $row->down_checks,
            ]);
        }
    }

    private function aggregateBackups(Carbon $start, Carbon $end): void
    {
        $rows = DB::select("
            SELECT
                site_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM backups
            WHERE created_at BETWEEN ? AND ?
            GROUP BY site_id
        ", [$start, $end]);

        foreach ($rows as $row) {
            $this->upsert($row->site_id, [
                'backups_total' => $row->total,
                'backups_successful' => $row->successful,
                'backups_failed' => $row->failed,
            ]);
        }
    }

    private function aggregateUpdates(Carbon $start, Carbon $end): void
    {
        // update_logs has no created_at column ($timestamps = false); the applied
        // timestamp is performed_at. Using created_at crashed the entire monthly
        // aggregation before it could write any snapshot columns (incl. the new
        // Cloudflare ones below), so the report snapshot was never built.
        $rows = DB::select('
            SELECT site_id, COUNT(*) as applied
            FROM update_logs
            WHERE performed_at BETWEEN ? AND ?
            GROUP BY site_id
        ', [$start, $end]);

        foreach ($rows as $row) {
            $this->upsert($row->site_id, [
                'updates_applied' => $row->applied,
            ]);
        }
    }

    private function aggregateSecurity(Carbon $start, Carbon $end): void
    {
        $rows = DB::select('
            SELECT site_id, AVG(score) as avg_score
            FROM security_scans
            WHERE scanned_at BETWEEN ? AND ?
            GROUP BY site_id
        ', [$start, $end]);

        foreach ($rows as $row) {
            $this->upsert($row->site_id, [
                'security_avg_score' => $row->avg_score,
            ]);
        }
    }

    private function aggregatePerformance(Carbon $start, Carbon $end): void
    {
        $rows = DB::select("
            SELECT
                pm.site_id,
                AVG(CASE WHEN pt.device = 'desktop' THEN pt.performance_score END) as avg_desktop,
                AVG(CASE WHEN pt.device = 'mobile' THEN pt.performance_score END) as avg_mobile
            FROM performance_tests pt
            JOIN performance_monitors pm ON pm.id = pt.performance_monitor_id
            WHERE pt.status = 'completed'
              AND pt.is_competitor = false
              AND pt.tested_at BETWEEN ? AND ?
            GROUP BY pm.site_id
        ", [$start, $end]);

        foreach ($rows as $row) {
            $this->upsert($row->site_id, [
                'performance_avg_desktop' => $row->avg_desktop,
                'performance_avg_mobile' => $row->avg_mobile,
            ]);
        }
    }

    private function aggregateAnalytics(): void
    {
        // Get latest analytics_cache entry per site (overview data for 28d range)
        $rows = DB::select("
            SELECT DISTINCT ON (ac.site_id)
                ac.site_id, ac.data
            FROM analytics_cache ac
            JOIN analytics_connections conn ON conn.site_id = ac.site_id
            WHERE ac.endpoint = 'overview'
              AND ac.date_range = '28d'
            ORDER BY ac.site_id, ac.created_at DESC
        ");

        foreach ($rows as $row) {
            $data = json_decode($row->data, true);
            if (! $data) {
                continue;
            }

            $this->upsert($row->site_id, [
                'analytics_users' => $data['users'] ?? null,
                'analytics_sessions' => $data['sessions'] ?? null,
                'analytics_pageviews' => $data['screenPageViews'] ?? $data['pageviews'] ?? null,
            ]);
        }
    }

    private function aggregateSearchConsole(): void
    {
        // Get latest search_console_cache entry per site (overview data for 28d range)
        $rows = DB::select("
            SELECT DISTINCT ON (sc.site_id)
                sc.site_id, sc.data
            FROM search_console_cache sc
            JOIN search_console_connections conn ON conn.site_id = sc.site_id
            WHERE sc.endpoint = 'overview'
              AND sc.date_range = '28d'
            ORDER BY sc.site_id, sc.created_at DESC
        ");

        foreach ($rows as $row) {
            $data = json_decode($row->data, true);
            if (! $data) {
                continue;
            }

            $this->upsert($row->site_id, [
                'search_console_clicks' => $data['clicks'] ?? null,
                'search_console_impressions' => $data['impressions'] ?? null,
                'search_console_avg_position' => $data['position'] ?? null,
            ]);
        }
    }

    /**
     * P2-02: the Cloudflare report section reads cloudflare_* snapshot columns that
     * nothing ever populated, so it always rendered "not available". Fill them from
     * the real zone analytics the app already has access to (requests, bandwidth,
     * cache hit ratio) for this exact month. Best-effort per site: a zone that fails
     * or has no data for the window leaves its columns null, which the gatherer
     * renders gracefully rather than as fake zeros.
     */
    private function aggregateCloudflare(Carbon $start, Carbon $end): void
    {
        $zones = \App\Models\SiteCloudflare::with('cloudflareConnection')
            ->where('is_active', true)
            ->get();

        foreach ($zones as $zone) {
            $connection = $zone->cloudflareConnection;

            if (! $connection || ! $connection->is_valid) {
                continue;
            }

            try {
                $service = new \App\Services\CloudflareService($connection);
                $analytics = $service->getAnalyticsForRange($zone->zone_id, $start, $end);
            } catch (\Throwable $e) {
                Log::warning("Cloudflare snapshot aggregation failed for site {$zone->site_id}: {$e->getMessage()}");

                continue;
            }

            $totals = $analytics['totals'] ?? [];
            $requests = (int) ($totals['requests']['all'] ?? 0);
            $cachedRequests = (int) ($totals['requests']['cached'] ?? 0);
            $bandwidth = (int) ($totals['bandwidth']['all'] ?? 0);

            // No traffic in the window → nothing meaningful to store; leave null so
            // the report omits the metrics rather than showing a fabricated zero.
            if ($requests <= 0 && $bandwidth <= 0) {
                continue;
            }

            $cacheHitRatio = $requests > 0
                ? round($cachedRequests / $requests * 100, 2)
                : null;

            $this->upsert($zone->site_id, [
                'cloudflare_requests' => $requests,
                'cloudflare_bandwidth_bytes' => $bandwidth,
                'cloudflare_cache_hit_ratio' => $cacheHitRatio,
            ]);
        }
    }

    private function aggregateIncidents(Carbon $start, Carbon $end): void
    {
        $rows = DB::select('
            SELECT um.site_id, COUNT(*) as incident_count
            FROM uptime_incidents ui
            JOIN uptime_monitors um ON um.id = ui.monitor_id
            WHERE ui.started_at BETWEEN ? AND ?
            GROUP BY um.site_id
        ', [$start, $end]);

        foreach ($rows as $row) {
            $this->upsert($row->site_id, [
                'uptime_incidents_count' => $row->incident_count,
            ]);
        }
    }

    private function upsert(int $siteId, array $data): void
    {
        SiteMonthlySnapshot::updateOrCreate(
            [
                'site_id' => $siteId,
                'year' => $this->year,
                'month' => $this->month,
            ],
            $data
        );
    }
}
