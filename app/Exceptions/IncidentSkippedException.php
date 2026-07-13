<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * P3-32: raised when an incident-response run is intentionally SKIPPED by a
 * safety guardrail — cooldown active, hourly rate limit reached, or the feature
 * being disabled. These are normal control flow (the SiteWentDown path trips
 * them routinely), NOT failures, so callers catch this type specifically and
 * log at debug/info instead of polluting the error log.
 *
 * Extends \RuntimeException so existing callers/tests that assert a
 * RuntimeException with the skip reason keep working unchanged.
 */
class IncidentSkippedException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
