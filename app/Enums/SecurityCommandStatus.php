<?php

namespace App\Enums;

enum SecurityCommandStatus: string
{
    case Pending = 'pending';
    case PickedUp = 'picked_up';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PickedUp => 'Picked Up',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::RolledBack => 'Rolled Back',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::PickedUp => 'purple',
            self::Completed => 'green',
            self::Failed => 'red',
            self::RolledBack => 'orange',
            self::Cancelled => 'gray',
        };
    }
}
