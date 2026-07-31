<?php

declare(strict_types=1);

namespace App\Backup\V2\Exceptions;

use App\Backup\V2\Enums\BackupErrorCode;
use RuntimeException;

/**
 * A precondition the backup cannot proceed without.
 *
 * Distinct from an engine error because it is not a fault: nothing broke, the
 * host is simply not in a state where a backup can succeed. It carries its own
 * error code so the alert names the precondition — "the host has 40 MB free" —
 * instead of reporting a generic failure three phases later, once the site has
 * already done the work.
 */
class PreflightFailed extends RuntimeException
{
    public function __construct(
        public readonly BackupErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
