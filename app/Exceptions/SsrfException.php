<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an outbound URL supplied by a user / managed site fails the
 * SSRF guard (private/loopback/reserved IP, non-http scheme, or an internal
 * Docker service hostname).
 */
class SsrfException extends RuntimeException {}
