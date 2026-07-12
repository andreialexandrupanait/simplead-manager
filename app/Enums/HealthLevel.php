<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical health-score banding for the whole app (P2-69).
 *
 * A single source of truth for how a 0–100 health score is bucketed into
 * good / warning / critical — for labels, colours, filters and query scopes
 * alike. Previously two systems disagreed: this enum's 75/50 and a divergent
 * 90/70 hard-coded in the sites list and dashboard, so the same score was
 * labelled differently in different views. The enum's 75/50 was the more
 * widely used set (scopes, cards, detail/portal views, status helper), so it
 * is canonical; the divergent 90/70 literals now defer to these constants.
 *
 * Bucket anything into a level with {@see self::fromScore()}; filter/query on
 * the {@see self::HEALTHY_THRESHOLD} / {@see self::WARNING_THRESHOLD} constants.
 */
enum HealthLevel: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    /** Score at or above which a site is Healthy. */
    public const HEALTHY_THRESHOLD = 75;

    /** Score at or above which a site is Warning (below is Critical). */
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
