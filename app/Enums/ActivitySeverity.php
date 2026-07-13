<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severity levels for activity-timeline events. Backed by the same string
 * values that were previously stored free-form, so no data migration is needed.
 */
enum ActivitySeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';
    case Success = 'success';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Info => 'Info',
            self::Success => 'Success',
        };
    }
}
