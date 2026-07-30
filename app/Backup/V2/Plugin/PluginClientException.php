<?php

declare(strict_types=1);

namespace App\Backup\V2\Plugin;

use RuntimeException;

/**
 * A simplead-backup plugin request failed (transport, auth, or a non-2xx JSON
 * error body). Carries the HTTP status + decoded error payload for diagnosis.
 */
final class PluginClientException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }
}
