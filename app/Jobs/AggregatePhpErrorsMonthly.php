<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fold PHP errors into one row per site, month, source and level (SPEC §14.1).
 *
 * Raw rows are kept 30 days; these are kept 13 months. Without them, §12.4's
 * sentence — "847 warnings from JetWooBuilder 2.1.3, fixed on 18 July, active
 * errors now: 0" — could only ever be written about the last 30 days, and the
 * year-on-year comparison would have nothing to stand on.
 *
 * Runs daily and re-folds a trailing window, so the current month is kept current
 * and a missed run repairs itself. It must run BEFORE RetentionCleanup, or the raw
 * rows would be pruned before they were ever folded up.
 */
class AggregatePhpErrorsMonthly implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Re-fold the last two months. One month is not enough: on the 1st the job has
     * to finish the month that just ended as well as start the new one.
     */
    private const LOOKBACK_MONTHS = 2;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3000;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'aggregate-php-errors-monthly';
    }

    public function handle(): void
    {
        $since = now()->subMonths(self::LOOKBACK_MONTHS)->startOfMonth();

        // Grouped in PostgreSQL rather than PHP: this scans every retained error row
        // on every site, and the grouping keys are exactly what an index can serve.
        $rows = DB::select(<<<'SQL'
            SELECT
                site_id,
                date_trunc('month', last_seen_at)::date AS bucket_month,
                COALESCE(source_type, 'unknown')        AS source_type,
                COALESCE(source_slug, '')               AS source_slug,
                level,
                COUNT(*)                                AS signatures,
                SUM(COALESCE(count, 1))                 AS occurrences,
                COUNT(*) FILTER (WHERE is_resolved)     AS resolved_count
            FROM php_error_logs
            WHERE last_seen_at >= ?
            GROUP BY site_id, date_trunc('month', last_seen_at), source_type, source_slug, level
        SQL, [$since->toDateTimeString()]);

        if ($rows === []) {
            return;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'site_id' => (int) $row->site_id,
                'bucket_month' => $row->bucket_month,
                'source_type' => $row->source_type,
                'source_slug' => $row->source_slug,
                'level' => $row->level,
                'signatures' => (int) $row->signatures,
                'occurrences' => (int) $row->occurrences,
                'resolved_count' => (int) $row->resolved_count,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('php_error_monthly_aggregates')->upsert(
                $chunk,
                ['site_id', 'bucket_month', 'source_type', 'source_slug', 'level'],
                ['signatures', 'occurrences', 'resolved_count', 'updated_at'],
            );
        }

        Log::info('PHP error monthly aggregation complete', [
            'buckets' => count($payload),
            'since' => $since->toDateString(),
        ]);
    }
}
