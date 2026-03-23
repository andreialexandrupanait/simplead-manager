<?php

declare(strict_types=1);

namespace App\Enums;

enum SecurityCommandPriority: string
{
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::High => 'orange',
            self::Critical => 'red',
        };
    }
}
