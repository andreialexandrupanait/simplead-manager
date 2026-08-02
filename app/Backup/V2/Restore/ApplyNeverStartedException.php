<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use RuntimeException;

/**
 * The host accepted the request to apply the restore in the background, and then never began it.
 *
 * Distinct from an apply that failed, because nothing has been touched: the site is exactly as it
 * was and the only trace is a status file saying the work was claimed. So this is recoverable by
 * simply doing it in-band, which is what the runner does — where a genuine apply failure would mean
 * rolling back instead.
 *
 * Seen for real: a loopback request the host accepted and then answered with a 500, leaving the
 * restore claimed-but-never-started while the manager polled it for the full apply deadline.
 */
final class ApplyNeverStartedException extends RuntimeException {}
