<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Roll raw uptime pings up into one row per monitor per hour (SPEC §14.1).
 *
 * Raw pings are kept 14 days; these aggregates are kept 13 months, which is what
 * makes "July this year against July last year" answerable. Without them, any
 * window longer than the raw retention silently covered only the unpruned tail.
 *
 * The job must run BEFORE RetentionCleanup each day, or the pings for the hours it
 * has not yet folded up would be deleted first. It aggregates a trailing window
 * rather than only the last hour so a missed run repairs itself, and it upserts on
 * (monitor_id, bucket_hour) so re-running corrects an hour instead of doubling it.
 */
class AggregateUptimeHourly implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How far back each run re-folds. Wide enough that a day of missed runs (a
     * queue outage, a deploy) repairs itself on the next one, and cheap because
     * the upsert simply rewrites hours that are already correct.
     */
    private const LOOKBACK_HOURS = 48;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3000;

    public function __construct(
        public ?Carbon $since = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'aggregate-uptime-hourly';
    }

    public function handle(): void
    {
        $since = $this->since ?? now()->subHours(self::LOOKBACK_HOURS);

        // The current hour is still filling; folding it now would store a partial
        // count that the next run would have to correct anyway.
        $until = now()->startOfHour();

        if ($since->gte($until)) {
            return;
        }

        // Aggregation happens in PostgreSQL rather than PHP: this is a grouped scan
        // over a table that reaches ~1M rows a month, and percentile_cont is exact
        // where a PHP-side approximation would not be.
        $rows = DB::select(<<<'SQL'
            SELECT
                c.monitor_id,
                m.site_id,
                date_trunc('hour', c.checked_at) AS bucket_hour,
                COUNT(*)                                                   AS checks_total,
                COUNT(*) FILTER (WHERE c.is_up)                            AS checks_up,
                ROUND(AVG(c.response_time))                                AS avg_response_time,
                ROUND(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY c.response_time)) AS p95_response_time,
                MAX(c.response_time)                                       AS max_response_time
            FROM uptime_checks c
            JOIN uptime_monitors m ON m.id = c.monitor_id
            WHERE c.checked_at >= ? AND c.checked_at < ?
            GROUP BY c.monitor_id, m.site_id, date_trunc('hour', c.checked_at)
        SQL, [$since->toDateTimeString(), $until->toDateTimeString()]);

        if ($rows === []) {
            return;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $total = (int) $row->checks_total;
            $up = (int) $row->checks_up;

            $payload[] = [
                'monitor_id' => (int) $row->monitor_id,
                'site_id' => $row->site_id !== null ? (int) $row->site_id : null,
                'bucket_hour' => $row->bucket_hour,
                'checks_total' => $total,
                'checks_up' => $up,
                'uptime_pct' => $total > 0 ? round(($up / $total) * 100, 3) : null,
                'avg_response_time' => $row->avg_response_time !== null ? (int) $row->avg_response_time : null,
                'p95_response_time' => $row->p95_response_time !== null ? (int) $row->p95_response_time : null,
                'max_response_time' => $row->max_response_time !== null ? (int) $row->max_response_time : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('uptime_hourly_aggregates')->upsert(
                $chunk,
                ['monitor_id', 'bucket_hour'],
                ['site_id', 'checks_total', 'checks_up', 'uptime_pct', 'avg_response_time', 'p95_response_time', 'max_response_time', 'updated_at'],
            );
        }

        Log::info('Uptime hourly aggregation complete', [
            'buckets' => count($payload),
            'from' => $since->toDateTimeString(),
            'to' => $until->toDateTimeString(),
        ]);
    }
}
