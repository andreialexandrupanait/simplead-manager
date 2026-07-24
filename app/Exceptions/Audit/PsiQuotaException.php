<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use RuntimeException;

/**
 * PageSpeed Insights quota exhaustion (HTTP 429 / RESOURCE_EXHAUSTED). Distinct
 * from a generic API error so the crawl can cite "quota" specifically and leave
 * 3.5/3.6 manual. Port of PsiQuotaError.
 */
final class PsiQuotaException extends RuntimeException
{
    public function __construct(string $message = 'PageSpeed Insights quota exceeded (HTTP 429).')
    {
        parent::__construct($message);
    }
}
