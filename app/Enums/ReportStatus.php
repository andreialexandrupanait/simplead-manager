<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Generating => 'Generating',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::Generating => 'purple',
            self::Pending => 'yellow',
            self::Failed => 'red',
        };
    }
}
