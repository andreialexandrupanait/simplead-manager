<?php

declare(strict_types=1);

namespace App\Enums;

enum SeoIssueSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Info => 'Info',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::High => 'orange',
            self::Medium => 'yellow',
            self::Low => 'blue',
            self::Info => 'gray',
        };
    }

    public function penalty(): int
    {
        return match ($this) {
            self::Critical => 20,
            self::High => 10,
            self::Medium => 5,
            self::Low => 2,
            self::Info => 0,
        };
    }

    public function maxPenalty(): int
    {
        return match ($this) {
            self::Critical => 60,
            self::High => 30,
            self::Medium => 20,
            self::Low => 10,
            self::Info => 0,
        };
    }
}
