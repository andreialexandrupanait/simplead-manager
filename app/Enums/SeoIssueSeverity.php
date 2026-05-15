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
            self::Critical => 'Critical', self::High => 'High', self::Medium => 'Medium', self::Low => 'Low', self::Info => 'Info'
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red', self::High => 'orange', self::Medium => 'yellow', self::Low => 'blue', self::Info => 'gray'
        };
    }

    public function penalty(): int
    {
        return match ($this) {
            self::Critical => 15, self::High => 8, self::Medium => 3, self::Low => 1, self::Info => 0
        };
    }
}
