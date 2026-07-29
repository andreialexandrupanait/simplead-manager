<?php

declare(strict_types=1);

namespace App\Operations;

use App\Services\JobTracker;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregate progress for a fleet operation run, held on the Cache.
 *
 * Modelled on {@see \App\Services\MaintenancePlanService}'s per-batch progress
 * record: because `job_batches` is not migrated we cannot use Bus::batch, so a
 * plain Cache record counts per-site completions instead. Each recorded outcome
 * is ALSO mirrored into {@see JobTracker} (progress % + completion) so the
 * existing UI that polls a tracker key keeps working unchanged.
 */
class OperationBatch
{
    /** Progress record TTL — long enough to outlive a slow fleet run. */
    private const TTL_HOURS = 6;

    /**
     * The JobTracker key for a run, derived deterministically from the run id so
     * every producer/consumer agrees without threading it around.
     */
    public static function trackerKey(string $runId): string
    {
        return "operation:{$runId}";
    }

    /**
     * Initialise the aggregate record for a run of $total sites and open the
     * mirrored JobTracker entry.
     */
    public static function init(string $runId, int $total, string $key): void
    {
        Cache::put(self::progressKey($runId), [
            'total' => $total,
            'done' => 0,
            'failed' => 0,
            'skipped' => 0,
            'key' => $key,
        ], now()->addHours(self::TTL_HOURS));

        Cache::put(self::perSiteKey($runId), [], now()->addHours(self::TTL_HOURS));

        JobTracker::start(self::trackerKey($runId), "Running {$key} on {$total} site(s)...");
    }

    /**
     * Record a single site's terminal outcome and advance both the aggregate
     * record and the mirrored JobTracker progress.
     */
    public static function record(string $runId, OperationOutcome $outcome, ?int $siteId = null): void
    {
        $key = self::progressKey($runId);
        $record = Cache::get($key);

        if (! is_array($record)) {
            return;
        }

        $record['done'] = ($record['done'] ?? 0) + 1;
        if ($outcome === OperationOutcome::Failure) {
            $record['failed'] = ($record['failed'] ?? 0) + 1;
        } elseif ($outcome === OperationOutcome::Skipped) {
            $record['skipped'] = ($record['skipped'] ?? 0) + 1;
        }

        Cache::put($key, $record, now()->addHours(self::TTL_HOURS));

        if ($siteId !== null) {
            $perSite = Cache::get(self::perSiteKey($runId));
            $perSite = is_array($perSite) ? $perSite : [];
            $perSite[$siteId] = $outcome->value;
            Cache::put(self::perSiteKey($runId), $perSite, now()->addHours(self::TTL_HOURS));
        }

        self::mirrorToTracker($runId, $record);
    }

    /**
     * Read the aggregate snapshot for a run.
     *
     * @return array{total: int, done: int, failed: int, skipped: int, complete: bool, key: string}
     */
    public static function snapshot(string $runId): array
    {
        $record = Cache::get(self::progressKey($runId));

        if (! is_array($record)) {
            return [
                'total' => 0,
                'done' => 0,
                'failed' => 0,
                'skipped' => 0,
                'complete' => false,
                'key' => '',
            ];
        }

        $total = (int) ($record['total'] ?? 0);
        $done = (int) ($record['done'] ?? 0);

        return [
            'total' => $total,
            'done' => $done,
            'failed' => (int) ($record['failed'] ?? 0),
            'skipped' => (int) ($record['skipped'] ?? 0),
            'complete' => $done >= $total && $total > 0,
            'key' => (string) ($record['key'] ?? ''),
        ];
    }

    /**
     * The per-site outcome map recorded so far, keyed by site id.
     *
     * @return array<int, string>
     */
    public static function perSite(string $runId): array
    {
        $perSite = Cache::get(self::perSiteKey($runId));

        return is_array($perSite) ? $perSite : [];
    }

    /**
     * @param  array{total?: int, done?: int}  $record
     */
    private static function mirrorToTracker(string $runId, array $record): void
    {
        $total = (int) ($record['total'] ?? 0);
        $done = (int) ($record['done'] ?? 0);
        $trackerKey = self::trackerKey($runId);

        if ($total > 0 && $done >= $total) {
            JobTracker::complete($trackerKey, 'Operation complete.');

            return;
        }

        $percent = $total > 0 ? (int) floor($done / $total * 100) : 0;
        JobTracker::progress($trackerKey, $percent, "{$done}/{$total} sites processed");
    }

    private static function progressKey(string $runId): string
    {
        return "operation-batch:{$runId}";
    }

    private static function perSiteKey(string $runId): string
    {
        return "operation-batch-sites:{$runId}";
    }
}
