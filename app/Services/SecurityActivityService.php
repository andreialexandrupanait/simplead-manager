<?php

namespace App\Services;

use App\Models\SecurityActivityLog;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SecurityActivityService
{
    public function ingestLogs(Site $site, array $logs): int
    {
        $logs = array_slice($logs, 0, 1000);

        $rows = [];
        $now = now();

        foreach ($logs as $log) {
            $rows[] = [
                'site_id' => $site->id,
                'event_type' => $log['event_type'] ?? 'unknown',
                'username' => $log['username'] ?? null,
                'object_type' => $log['object_type'] ?? null,
                'object_name' => $log['object_name'] ?? null,
                'action' => $log['action'] ?? null,
                'ip_address' => $log['ip_address'] ?? null,
                'user_agent' => isset($log['user_agent']) ? substr($log['user_agent'], 0, 500) : null,
                'details' => isset($log['details']) ? json_encode($log['details']) : null,
                'occurred_at' => $log['occurred_at'] ?? $now,
                'created_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        // Bulk insert in chunks
        foreach (array_chunk($rows, 500) as $chunk) {
            SecurityActivityLog::insert($chunk);
        }

        return count($rows);
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
}
