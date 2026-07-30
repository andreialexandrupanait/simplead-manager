<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use RuntimeException;

/**
 * A staged object failed verify-before-apply (missing, or sha256 mismatch vs what the plugin
 * confirmed on staging). Raised BEFORE the site is mutated so the restore refuses instead of
 * applying a torn artifact.
 */
final class RestoreVerifyException extends RuntimeException {}
