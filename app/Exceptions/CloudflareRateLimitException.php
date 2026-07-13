<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * P2-65: raised when the local Cloudflare API rate-limit window is exhausted.
 *
 * Unlike a plain failure, this is transient and self-healing: the caller should
 * DEFER (release a queued job back with $retryAfter) rather than abort. It
 * carries the number of seconds until the window frees up so callers can size
 * their retry delay precisely instead of guessing.
 */
class CloudflareRateLimitException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfter = 60,
        string $message = 'Cloudflare API rate limit exceeded.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
