<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use RuntimeException;
use Throwable;

/**
 * Any non-quota PageSpeed Insights failure (bad request, non-JSON body, missing
 * lighthouseResult, connection error). Port of PsiApiError.
 */
final class PsiApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
