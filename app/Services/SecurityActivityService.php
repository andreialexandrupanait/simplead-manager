<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SecurityActivityLog;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SecurityActivityService
{
    /**
     * Clock-skew tolerance when clamping remote `occurred_at` values. A row dated
     * further than this into the future is treated as clock skew / tampering and
     * clamped to "now" so it can never jump the ingestion watermark past real
     * events (P1-11).
     */
    private const FUTURE_SKEW_MINUTES = 5;

    public function ingestLogs(Site $site, array $logs): int
    {
        $rows = [];
        $now = now();

        foreach ($logs as $log) {
            // P1-11: every remote field is untrusted and flows into typed Postgres
            // columns (inet, varchar, timestamp). Validate/clamp each one so a
            // single malformed row can never fail the batch insert and wedge this
            // site's audit ingestion forever.
            $eventType = self::clampString($log['event_type'] ?? null, 50) ?? 'unknown';

            $rows[] = [
                'site_id' => $site->id,
                'event_type' => $eventType,
                'event_category' => self::categorizeEvent($eventType),
                'username' => self::clampString($log['username'] ?? null, 255),
                'object_type' => self::clampString($log['object_type'] ?? null, 50),
                'object_name' => self::clampString($log['object_name'] ?? null, 255),
                'action' => self::clampString($log['action'] ?? null, 100),
                'ip_address' => self::validIp($log['ip_address'] ?? null),
                'user_agent' => self::clampString($log['user_agent'] ?? null, 500),
                'details' => isset($log['details']) ? json_encode($log['details']) : null,
                'occurred_at' => self::clampOccurredAt($log['occurred_at'] ?? null, $now),
                'created_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        return $this->insertRowTolerant($rows);
    }

    /**
     * The newest (clamped) occurred_at across a batch, formatted as naive-UTC for
     * use as the `since` pagination cursor. Null when the batch is empty. Uses the
     * same clamping as ingestion so the cursor matches what is persisted.
     */
    public function latestCursor(array $logs): ?string
    {
        $now = now();
        $max = null;

        foreach ($logs as $log) {
            $ts = self::clampOccurredAt($log['occurred_at'] ?? null, $now);
            if ($max === null || $ts > $max) {
                $max = $ts;
            }
        }

        return $max;
    }

    /**
     * Insert in chunks, but never let one poisoned row stall the whole site's
     * ingestion: on a chunk failure, retry the chunk row-by-row and drop only the
     * offending rows (P1-11 — the batch must be row-tolerant).
     */
    private function insertRowTolerant(array $rows): int
    {
        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            try {
                SecurityActivityLog::insert($chunk);
                $inserted += count($chunk);
            } catch (\Throwable) {
                foreach ($chunk as $row) {
                    try {
                        SecurityActivityLog::insert([$row]);
                        $inserted++;
                    } catch (\Throwable $inner) {
                        Log::warning('SecurityActivityService: dropped a malformed audit row', [
                            'site_id' => $row['site_id'] ?? null,
                            'error' => $inner->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $inserted;
    }

    private static function clampString(mixed $value, int $max): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        return mb_substr((string) $value, 0, $max);
    }

    private static function validIp(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $value;
    }

    private static function clampOccurredAt(mixed $value, Carbon $now): string
    {
        $parsed = null;

        if (is_string($value) && trim($value) !== '') {
            try {
                // Connector timestamps are naive-UTC DATETIMEs; treat a value with
                // no timezone as UTC (never the container's local tz). A value that
                // does carry an offset is honoured, then normalised to UTC below.
                $parsed = Carbon::parse($value, 'UTC');
            } catch (\Throwable) {
                $parsed = null;
            }
        } elseif ($value instanceof \DateTimeInterface) {
            $parsed = Carbon::instance($value);
        }

        if ($parsed === null || $parsed->greaterThan($now->copy()->addMinutes(self::FUTURE_SKEW_MINUTES))) {
            $parsed = $now;
        }

        return $parsed->utc()->format('Y-m-d H:i:s');
    }

    public function getRecentActivity(Site $site, int $days = 7): Collection
    {
        return SecurityActivityLog::where('site_id', $site->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();
    }

    public function getFailedLoginStats(Site $site, int $days = 7): array
    {
        $cutoff = now()->subDays($days);

        $baseQuery = SecurityActivityLog::where('site_id', $site->id)
            ->where('event_type', 'failed_login')
            ->where('occurred_at', '>=', $cutoff);

        $stats = (clone $baseQuery)
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT ip_address) as unique_ips'),
                DB::raw('COUNT(DISTINCT username) as unique_usernames'),
            ])
            ->first();

        $topIps = (clone $baseQuery)
            ->whereNotNull('ip_address')
            ->select('ip_address', DB::raw('COUNT(*) as attempts'))
            ->groupBy('ip_address')
            ->orderByDesc('attempts')
            ->limit(10)
            ->get();

        return [
            'total' => $stats->total ?? 0,
            'unique_ips' => $stats->unique_ips ?? 0,
            'unique_usernames' => $stats->unique_usernames ?? 0,
            'top_ips' => $topIps,
        ];
    }

    public function pruneOldLogs(int $retentionDays): int
    {
        return SecurityActivityLog::where('occurred_at', '<', now()->subDays($retentionDays))->delete();
    }

    public static function categorizeEvent(string $eventType): string
    {
        if (str_starts_with($eventType, 'backup_') || str_starts_with($eventType, 'restore_') || str_starts_with($eventType, 'direct_upload')) {
            return 'backup';
        }
        if (str_starts_with($eventType, 'plugin_') || str_starts_with($eventType, 'theme_')) {
            return 'plugin';
        }
        if (str_starts_with($eventType, 'user_') || str_starts_with($eventType, 'login_') || $eventType === 'auto_login') {
            return 'user';
        }

        return 'security';
    }
}
