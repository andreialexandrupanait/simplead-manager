<?php

declare(strict_types=1);

namespace App\Backup\V2\Exceptions;

use App\Backup\V2\Enums\BackupErrorCode;
use RuntimeException;

/**
 * The site stopped keeping up with its own backup.
 *
 * Not a fault in the engine and not a fault on the host — a judgement that
 * continuing would cost the site's visitors more than finishing tonight is
 * worth. It parks the session rather than failing it: the checkpoint is intact,
 * so the cost of being wrong is a delay, not a lost backup.
 */
class SiteUnderStrain extends RuntimeException
{
    public function __construct(
        public readonly BackupErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
