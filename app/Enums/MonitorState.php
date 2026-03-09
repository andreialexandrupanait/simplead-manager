<?php

namespace App\Enums;

enum MonitorState: string
{
    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Up',
            self::Down => 'Down',
            self::Degraded => 'Degraded',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Up => 'green',
            self::Down => 'red',
            self::Degraded => 'yellow',
            self::Unknown => 'gray',
        };
    }
}
