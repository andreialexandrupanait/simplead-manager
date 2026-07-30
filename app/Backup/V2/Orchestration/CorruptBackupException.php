<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

use RuntimeException;

/**
 * Raised when a backup fails a verify-before-complete integrity check (a declared
 * object is missing or its stored checksum/size does not match, or the DB dump did
 * not complete a consistent single-snapshot run). The runner catches this and
 * moves the session to `corrupt` — never `completed`.
 */
final class CorruptBackupException extends RuntimeException {}
