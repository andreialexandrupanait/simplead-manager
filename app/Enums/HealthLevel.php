<?php

namespace App\Enums;

enum HealthLevel: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public const HEALTHY_THRESHOLD = 75;

    public const WARNING_THRESHOLD = 50;

    public static function fromScore(?int $score, bool $isUp = true): self
    {
        if (! $isUp) {
            return self::Critical;
        }

        if ($score === null) {
            return self::Unknown;
        }

        if ($score >= self::HEALTHY_THRESHOLD) {
            return self::Healthy;
        }

        if ($score >= self::WARNING_THRESHOLD) {
            return self::Warning;
        }

        return self::Critical;
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'green',
            self::Warning => 'yellow',
            self::Critical => 'red',
            self::Unknown => 'gray',
        };
    }

    public function bgColor(): string
    {
        return match ($this) {
            self::Healthy => 'bg-green-500',
            self::Warning => 'bg-yellow-500',
            self::Critical => 'bg-red-500',
            self::Unknown => 'bg-gray-500',
        };
    }
}
