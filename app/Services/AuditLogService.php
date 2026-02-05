<?php

namespace App\Services;

use App\Models\Site;
use App\Models\WpAuditLog;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    public static function sync(Site $site): array
    {
        try {
            $api = new WordPressApiService($site);

            // Get the last synced timestamp
            $lastLog = WpAuditLog::where('site_id', $site->id)
                ->orderByDesc('action_at')
                ->first();

            $since = $lastLog?->action_at?->toIso8601String();
            $result = $api->getAuditLogs($since);

            $logs = $result['logs'] ?? [];
            $synced = 0;

            foreach ($logs as $log) {
                $actionAt = isset($log['action_at']) ? \Carbon\Carbon::parse($log['action_at']) : now();

                // Skip if we already have this exact entry
                $exists = WpAuditLog::where('site_id', $site->id)
                    ->where('action_type', $log['action_type'] ?? '')
                    ->where('action_at', $actionAt)
                    ->where('wp_user_id', $log['wp_user_id'] ?? null)
                    ->exists();

                if ($exists) continue;

                WpAuditLog::create([
                    'site_id' => $site->id,
                    'wp_user_id' => $log['wp_user_id'] ?? null,
                    'wp_username' => $log['wp_username'] ?? null,
                    'user_role' => $log['user_role'] ?? null,
                    'action_type' => $log['action_type'] ?? 'unknown',
                    'object_type' => $log['object_type'] ?? null,
                    'object_id' => $log['object_id'] ?? null,
                    'object_title' => $log['object_title'] ?? null,
                    'old_value' => $log['old_value'] ?? null,
                    'new_value' => $log['new_value'] ?? null,
                    'ip_address' => $log['ip_address'] ?? null,
                    'user_agent' => $log['user_agent'] ?? null,
                    'action_at' => $actionAt,
                ]);
                $synced++;
            }

            return ['synced' => $synced];
        } catch (\Exception $e) {
            Log::warning("Audit log sync failed for site {$site->id}: {$e->getMessage()}");
            return ['synced' => 0, 'error' => $e->getMessage()];
        }
    }

    public static function export(Site $site, array $filters = []): string
    {
        $query = WpAuditLog::where('site_id', $site->id)->orderByDesc('action_at');

        if (!empty($filters['action_type']) && $filters['action_type'] !== 'all') {
            $query->where('action_type', $filters['action_type']);
        }
        if (!empty($filters['wp_username']) && $filters['wp_username'] !== 'all') {
            $query->where('wp_username', $filters['wp_username']);
        }
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('object_title', 'like', "%{$filters['search']}%")
                  ->orWhere('wp_username', 'like', "%{$filters['search']}%")
                  ->orWhere('ip_address', 'like', "%{$filters['search']}%");
            });
        }

        $logs = $query->get();

        $filename = 'audit-log-' . $site->domain . '-' . now()->format('Y-m-d') . '.csv';
        $path = storage_path('app/exports/' . $filename);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, ['Timestamp', 'User', 'Role', 'Action', 'Object Type', 'Object', 'IP Address']);

        foreach ($logs as $log) {
            fputcsv($handle, [
                $log->action_at?->format('Y-m-d H:i:s'),
                $log->wp_username,
                $log->user_role,
                $log->action_label,
                $log->object_type,
                $log->object_title,
                $log->ip_address,
            ]);
        }

        fclose($handle);

        return $path;
    }
}
