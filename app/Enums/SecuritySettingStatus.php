<?php

declare(strict_types=1);

namespace App\Enums;

enum SecuritySettingStatus: string
{
    case NotConfigured = 'not_configured';
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Not Configured',
            self::Pending => 'Pending',
            self::Applied => 'Applied',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotConfigured => 'gray',
            self::Pending => 'yellow',
            self::Applied => 'green',
            self::Failed => 'red',
        };
    }
}
