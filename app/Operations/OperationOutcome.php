<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * The terminal outcome of a single per-site operation run.
 *
 * Skipped is distinct from Failure: a skip means the operation did not apply to
 * this site (e.g. no Cloudflare zone) and is NOT an error, so it never trips a
 * breaker or fails a batch.
 */
enum OperationOutcome: string
{
    case Success = 'success';

    case Failure = 'failure';

    case Skipped = 'skipped';
}
