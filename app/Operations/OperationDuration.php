<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * The expected cost class of an operation, which decides the Horizon queue it
 * runs on plus its retry/timeout budget. Reuses the existing supervisors
 * (uptime / default / backups) so we don't fragment the worker pool, adding a
 * single dedicated fast lane ('ops-instant') for cheap synchronous API calls.
 */
enum OperationDuration: string
{
    case Instant = 'ops-instant';

    case Short = 'uptime';

    case Medium = 'default';

    case Long = 'backups';

    /**
     * The Horizon queue this duration class dispatches onto.
     */
    public function queue(): string
    {
        return $this->value;
    }

    /**
     * Retry budget for a job in this duration class.
     */
    public function tries(): int
    {
        return match ($this) {
            self::Instant => 2,
            self::Short => 3,
            self::Medium => 3,
            self::Long => 2,
        };
    }

    /**
     * Worker timeout (seconds) for a job in this duration class. Kept safely
     * below the redis retry_after so a worker never kills a job that is still
     * legitimately in flight.
     */
    public function timeout(): int
    {
        return match ($this) {
            self::Instant => 60,
            self::Short => 150,
            self::Medium => 600,
            self::Long => 3600,
        };
    }
}
