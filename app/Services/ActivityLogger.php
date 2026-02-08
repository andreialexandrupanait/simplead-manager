<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\User;

class ActivityLogger
{
    public static function log(
        string $type,
        string $severity,
        string $title,
        ?string $description = null,
        ?Site $site = null,
        ?array $metadata = null,
        ?string $icon = null,
        ?string $url = null,
    ): ActivityLog {
        return ActivityLog::create([
            'site_id' => $site?->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'icon' => $icon,
            'url' => $url,
            'created_at' => now(),
        ]);
    }

    public static function siteDown(Site $site, string $reason): ActivityLog
    {
        return static::log(
            type: 'uptime',
            severity: 'critical',
            title: "{$site->name} is down",
            description: $reason,
            site: $site,
            metadata: ['reason' => $reason],
            icon: 'activity',
            url: route('sites.uptime', $site),
        );
    }

    public static function siteRecovered(Site $site, int $downtimeMinutes): ActivityLog
    {
        return static::log(
            type: 'uptime',
            severity: 'success',
            title: "{$site->name} is back up",
            description: $downtimeMinutes > 0 ? "Recovered after {$downtimeMinutes} minutes of downtime" : 'Recovered',
            site: $site,
            metadata: ['downtime_minutes' => $downtimeMinutes],
            icon: 'activity',
            url: route('sites.uptime', $site),
        );
    }

    public static function backupCompleted(Site $site, string $fileName, int $fileSize): ActivityLog
    {
        $sizeMb = round($fileSize / 1024 / 1024, 1);

        return static::log(
            type: 'backup',
            severity: 'success',
            title: "Backup completed for {$site->name}",
            description: "{$fileName} ({$sizeMb} MB)",
            site: $site,
            metadata: ['file_name' => $fileName, 'file_size' => $fileSize],
            icon: 'hard-drive',
            url: route('sites.backups', $site),
        );
    }

    public static function backupFailed(Site $site, string $error): ActivityLog
    {
        return static::log(
            type: 'backup',
            severity: 'critical',
            title: "Backup failed for {$site->name}",
            description: $error,
            site: $site,
            metadata: ['error' => $error],
            icon: 'hard-drive',
            url: route('sites.backups', $site),
        );
    }

    public static function pluginUpdated(Site $site, string $name, ?string $from, ?string $to): ActivityLog
    {
        return static::log(
            type: 'update',
            severity: 'info',
            title: "Plugin updated on {$site->name}",
            description: "{$name}: {$from} → {$to}",
            site: $site,
            metadata: ['plugin' => $name, 'from' => $from, 'to' => $to],
            icon: 'refresh-cw',
            url: route('sites.updates', $site),
        );
    }

    public static function themeUpdated(Site $site, string $name, ?string $from, ?string $to): ActivityLog
    {
        return static::log(
            type: 'update',
            severity: 'info',
            title: "Theme updated on {$site->name}",
            description: "{$name}: {$from} → {$to}",
            site: $site,
            metadata: ['theme' => $name, 'from' => $from, 'to' => $to],
            icon: 'refresh-cw',
            url: route('sites.updates', $site),
        );
    }

    public static function coreUpdated(Site $site, ?string $from, ?string $to): ActivityLog
    {
        return static::log(
            type: 'update',
            severity: 'info',
            title: "WordPress core updated on {$site->name}",
            description: "WordPress {$from} → {$to}",
            site: $site,
            metadata: ['from' => $from, 'to' => $to],
            icon: 'refresh-cw',
            url: route('sites.updates', $site),
        );
    }

    public static function performanceScoreDrop(Site $site, string $device, int $from, int $to): ActivityLog
    {
        return static::log(
            type: 'performance',
            severity: 'warning',
            title: "Performance drop on {$site->name}",
            description: ucfirst($device) . " score dropped from {$from} to {$to}",
            site: $site,
            metadata: ['device' => $device, 'from' => $from, 'to' => $to],
            icon: 'zap',
            url: route('sites.performance', $site),
        );
    }

    public static function linkScanCompleted(Site $site, int $brokenCount, int $totalCount): ActivityLog
    {
        $severity = $brokenCount > 5 ? 'warning' : 'info';

        return static::log(
            type: 'links',
            severity: $severity,
            title: "Link scan completed for {$site->name}",
            description: "{$brokenCount} broken out of {$totalCount} links",
            site: $site,
            metadata: ['broken_count' => $brokenCount, 'total_count' => $totalCount],
            icon: 'link',
            url: route('sites.links', $site),
        );
    }

    public static function reportGenerated(Site $site, string $title): ActivityLog
    {
        return static::log(
            type: 'report',
            severity: 'info',
            title: "Report generated for {$site->name}",
            description: $title,
            site: $site,
            metadata: ['report_title' => $title],
            icon: 'file-text',
            url: route('sites.reports', $site),
        );
    }

    public static function reportSent(Site $site, string $title, array $recipients): ActivityLog
    {
        return static::log(
            type: 'report',
            severity: 'success',
            title: "Report sent for {$site->name}",
            description: "{$title} sent to " . count($recipients) . ' recipient(s)',
            site: $site,
            metadata: ['report_title' => $title, 'recipients' => $recipients],
            icon: 'file-text',
            url: route('sites.reports', $site),
        );
    }

    public static function appBackupCompleted(string $fileName, int $fileSize): ActivityLog
    {
        $sizeMb = round($fileSize / 1024 / 1024, 1);

        return static::log(
            type: 'app_backup',
            severity: 'success',
            title: 'Application backup completed',
            description: "{$fileName} ({$sizeMb} MB)",
            metadata: ['file_name' => $fileName, 'file_size' => $fileSize],
            icon: 'hard-drive',
            url: route('settings.application-backup'),
        );
    }

    public static function appBackupFailed(string $error): ActivityLog
    {
        return static::log(
            type: 'app_backup',
            severity: 'critical',
            title: 'Application backup failed',
            description: $error,
            metadata: ['error' => $error],
            icon: 'hard-drive',
            url: route('settings.application-backup'),
        );
    }

    public static function appDatabaseRestored(string $backupDate): ActivityLog
    {
        return static::log(
            type: 'app_backup',
            severity: 'warning',
            title: 'Application database restored',
            description: "Restored from backup dated {$backupDate}",
            metadata: ['backup_date' => $backupDate],
            icon: 'hard-drive',
            url: route('settings.application-backup'),
        );
    }

    public static function userLogin(User $user): ActivityLog
    {
        return static::log(
            type: 'auth',
            severity: 'info',
            title: "{$user->name} logged in",
            metadata: [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            icon: 'log-in',
        );
    }

    public static function userLogout(User $user): ActivityLog
    {
        return static::log(
            type: 'auth',
            severity: 'info',
            title: "{$user->name} logged out",
            metadata: [
                'ip' => request()->ip(),
            ],
            icon: 'log-out',
        );
    }

    public static function userLoginFailed(string $email): ActivityLog
    {
        return ActivityLog::create([
            'site_id' => null,
            'user_id' => null,
            'type' => 'auth',
            'severity' => 'warning',
            'title' => "Failed login attempt for {$email}",
            'metadata' => [
                'email' => $email,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            'icon' => 'alert-triangle',
            'created_at' => now(),
        ]);
    }
}
