<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentTriggerType: string
{
    case SiteDown = 'site_down';
    case SecurityCritical = 'security_critical';
    case Vulnerability = 'vulnerability';
    case PerformanceDrop = 'performance_drop';
    case DatabaseCritical = 'database_critical';

    public function label(): string
    {
        return match ($this) {
            self::SiteDown => 'Site Down',
            self::SecurityCritical => 'Security Critical',
            self::Vulnerability => 'Vulnerability',
            self::PerformanceDrop => 'Performance Drop',
            self::DatabaseCritical => 'Database Critical',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SiteDown => 'wifi-off',
            self::SecurityCritical => 'shield-alert',
            self::Vulnerability => 'bug',
            self::PerformanceDrop => 'gauge',
            self::DatabaseCritical => 'database',
        };
    }

    public function severity(): string
    {
        return match ($this) {
            self::SiteDown, self::SecurityCritical => 'critical',
            self::Vulnerability => 'warning',
            self::PerformanceDrop, self::DatabaseCritical => 'warning',
        };
    }
}
