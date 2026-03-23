<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Enums\HealthLevel;
use App\Models\Site;

class SiteStatusHelper
{
    /**
     * Compute all 12 status indicators for a site.
     * Returns an array with color and tip for each indicator.
     */
    public static function compute(Site $site): array
    {
        $updates = $site->pending_updates_count ?? 0;

        // Compute the 5 health-bar dimensions
        $uptime = static::uptime($site);
        $performance = static::performance($site);
        $backup = static::backup($site);
        $plugins = static::plugins($site, $updates);
        $wpVersion = static::wpVersion($site);

        // Calculate health score from dimension colors
        $possible = 0;
        $earned = 0;
        foreach ([$uptime, $performance, $backup, $plugins, $wpVersion] as $dim) {
            if ($dim['color'] === 'text-gray-300') {
                continue;
            }
            $possible++;
            if ($dim['color'] === 'text-green-500') {
                $earned += 1;
            } elseif ($dim['color'] === 'text-yellow-500') {
                $earned += 0.5;
            }
        }
        $healthScore = $possible > 0 ? (int) round(($earned / $possible) * 100) : 0;

        return [
            'uptime' => $uptime,
            'response' => static::responseTime($site),
            'performance' => $performance,
            'plugins' => $plugins,
            'users' => static::users($site),
            'wpConn' => static::wpConnection($site),
            'backup' => $backup,
            'wpVersion' => $wpVersion,
            'reports' => static::reports($site),
            'searchConsole' => static::searchConsole($site),
            'updates' => $updates,
            'updateBadgeColor' => $updates === 0 ? 'bg-green-500' : ($updates <= 5 ? 'bg-orange-500' : 'bg-red-500'),
            'healthScore' => $healthScore,
            'healthBarColor' => HealthLevel::fromScore($healthScore)->bgColor(),
        ];
    }

    private static function uptime(Site $site): array
    {
        $color = 'text-gray-300';
        $tip = 'Uptime: Not monitored';

        if ($site->uptimeMonitor) {
            if ($site->is_up === true) {
                $color = 'text-green-500';
                $tip = 'Site is up'.($site->uptime_percentage ? ' — '.$site->uptime_percentage.'%' : '');
            } elseif ($site->is_up === false) {
                $color = 'text-red-500';
                $tip = 'Site is DOWN';
            } else {
                $color = 'text-yellow-500';
                $tip = 'Uptime: Checking...';
            }
        }

        return compact('color', 'tip');
    }

    private static function responseTime(Site $site): array
    {
        $color = 'text-gray-300';
        $tip = 'Response time: N/A';

        if ($site->uptimeMonitor && $site->uptimeMonitor->avg_response_time) {
            $rt = $site->uptimeMonitor->avg_response_time;
            $color = $rt < 500 ? 'text-green-500' : ($rt <= 2000 ? 'text-yellow-500' : 'text-red-500');
            $tip = 'Response: '.number_format($rt).'ms';
        }

        return compact('color', 'tip');
    }

    private static function performance(Site $site): array
    {
        $color = 'text-gray-300';
        $tip = 'Performance: Not monitored';

        if ($site->performanceMonitor) {
            $score = $site->performanceMonitor->latest_mobile_score ?? $site->performanceMonitor->latest_desktop_score;
            if ($score !== null) {
                $color = $score >= 90 ? 'text-green-500' : ($score >= 50 ? 'text-yellow-500' : 'text-red-500');
                $tip = 'Performance: '.$score;
            }
        }

        return compact('color', 'tip');
    }

    private static function plugins(Site $site, int $updates): array
    {
        $color = 'text-gray-300';
        $tip = 'Plugins: Not connected';

        if ($site->is_connected) {
            $color = $updates === 0 ? 'text-green-500' : ($updates <= 5 ? 'text-yellow-500' : 'text-red-500');
            $tip = $updates === 0 ? 'All plugins up to date' : $updates.' update'.($updates > 1 ? 's' : '').' available';
        }

        return compact('color', 'tip');
    }

    private static function users(Site $site): array
    {
        $count = $site->site_users_count ?? 0;
        $color = $count > 0 ? 'text-green-500' : 'text-gray-300';
        $tip = $count > 0 ? $count.' user'.($count > 1 ? 's' : '') : 'No users tracked';

        return compact('color', 'tip');
    }

    private static function wpConnection(Site $site): array
    {
        $color = $site->is_connected ? 'text-green-500' : 'text-gray-300';
        $tip = $site->is_connected ? 'WordPress connected' : 'Not connected';

        return compact('color', 'tip');
    }

    private static function backup(Site $site): array
    {
        $color = 'text-gray-300';
        $tip = 'Backups: Not configured';

        if ($site->backupConfig) {
            $bc = $site->backupConfig;
            if (! $bc->is_enabled) {
                $tip = 'Backups: Disabled';
            } elseif ($bc->last_backup_status === 'failed') {
                $color = 'text-red-500';
                $tip = 'Last backup failed';
            } elseif ($site->last_backup_at) {
                $maxHours = match ($bc->frequency ?? 'daily') {
                    'daily' => 26, 'weekly' => 170, 'monthly' => 745, default => 26,
                };
                if ($site->last_backup_at->diffInHours(now()) > $maxHours) {
                    $color = 'text-yellow-500';
                    $tip = 'Backup overdue — last: '.$site->last_backup_at->diffForHumans();
                } else {
                    $color = 'text-green-500';
                    $tip = 'Last backup: '.$site->last_backup_at->diffForHumans();
                }
            } else {
                $color = 'text-yellow-500';
                $tip = 'Pending first backup';
            }
        }

        return compact('color', 'tip');
    }

    private static function wpVersion(Site $site): array
    {
        $color = 'text-gray-300';
        $tip = 'WP version: Unknown';

        if ($site->wp_version) {
            if ($site->core_update_version) {
                $color = 'text-yellow-500';
                $tip = 'WP '.$site->wp_version.' → '.$site->core_update_version;
            } else {
                $color = 'text-green-500';
                $tip = 'WP '.$site->wp_version.' (latest)';
            }
        }

        return compact('color', 'tip');
    }

    private static function reports(Site $site): array
    {
        $count = $site->report_schedules_count ?? 0;
        $color = $count > 0 ? 'text-green-500' : 'text-gray-300';
        $tip = $count > 0 ? $count.' report schedule'.($count > 1 ? 's' : '') : 'Reports';

        return compact('color', 'tip');
    }

    private static function searchConsole(Site $site): array
    {
        $color = 'text-gray-300';
        $tip = 'Search Console: Not connected';

        $conn = $site->searchConsoleConnection;
        if ($conn) {
            if (! $conn->is_active) {
                $color = 'text-gray-300';
                $tip = 'Search Console: Disabled';
            } elseif ($conn->last_error) {
                $color = 'text-red-500';
                $tip = 'Search Console: Error';
            } elseif ($conn->last_sync_at) {
                $color = 'text-green-500';
                $tip = 'Search Console: Synced '.$conn->last_sync_at->diffForHumans();
            } else {
                $color = 'text-yellow-500';
                $tip = 'Search Console: Pending first sync';
            }
        }

        return compact('color', 'tip');
    }
}
