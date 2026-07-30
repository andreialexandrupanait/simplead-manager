<?php

declare(strict_types=1);

namespace App\Backup\V2\Quota;

use App\Models\StorageDestination;
use RuntimeException;

/**
 * Thrown when a new V2 backup would push a StorageDestination past its quota.
 * Carries a human-facing message the UI surfaces verbatim.
 */
final class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly StorageDestination $destination,
        public readonly int $usedBytes,
        public readonly int $quotaBytes,
        public readonly int $projectedBytes,
        string $message,
    ) {
        parent::__construct($message);
    }
}
