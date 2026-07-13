<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical set of activity-timeline event types.
 *
 * Every type written via {@see \App\Services\ActivityLogger} (or a direct
 * ActivityLog::create) MUST have a case here so the model cast never sees an
 * unknown value and the timeline filter can offer it. The cast is applied
 * through {@see \App\Casts\SafeBackedEnum} so a legacy/unknown string on an
 * historical row degrades to null instead of throwing on read.
 */
enum ActivityType: string
{
    case Uptime = 'uptime';
    case Backup = 'backup';
    case Update = 'update';
    case Plugin = 'plugin';
    case Security = 'security';
    case Auth = 'auth';
    case Performance = 'performance';
    case Report = 'report';
    case AppBackup = 'app_backup';
    case Retention = 'retention';
    case User = 'user';
    case Database = 'database';
    case Dns = 'dns';
    case ErrorLog = 'error_log';
    case IncidentResponse = 'incident_response';
    case Seo = 'seo';
    case SeoFix = 'seo_fix';
    case Webhook = 'webhook';
    case ConnectionError = 'connection_error';
    case Portal = 'portal';

    public function label(): string
    {
        return match ($this) {
            self::Uptime => 'Uptime',
            self::Backup => 'Backups',
            self::Update => 'Updates',
            self::Plugin => 'Plugins',
            self::Security => 'Security',
            self::Auth => 'Authentication',
            self::Performance => 'Performance',
            self::Report => 'Reports',
            self::AppBackup => 'App Backup',
            self::Retention => 'Cleanup',
            self::User => 'Users',
            self::Database => 'Database',
            self::Dns => 'DNS',
            self::ErrorLog => 'Error Logs',
            self::IncidentResponse => 'Incident Response',
            self::Seo => 'SEO',
            self::SeoFix => 'SEO Fixes',
            self::Webhook => 'Webhooks',
            self::ConnectionError => 'Connection',
            self::Portal => 'Client Portal',
        };
    }

    /**
     * Options for the timeline filter dropdown, keyed by backing value, with a
     * leading "all" pseudo-option.
     *
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = ['all' => 'All'];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
