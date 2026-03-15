<?php

namespace App\Enums;

enum BackupStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::InProgress => 'purple',
            self::Pending => 'yellow',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Completed => 'check-circle',
            self::InProgress => 'loader',
            self::Pending => 'clock',
            self::Failed => 'x-circle',
            self::Cancelled => 'x-circle',
        };
    }
}
