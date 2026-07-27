<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use RuntimeException;

/**
 * Control-flow signal: a restore was rolled back to its pre-apply state (site is safe) and the
 * session is already Failed. Caught by RestoreRunner::run() so it does not double-handle.
 */
final class RestoreRolledBack extends RuntimeException {}
