<?php

declare(strict_types=1);

namespace App\Backup\V2\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Structured, context-rich logging for the V2 backup/restore engine.
 *
 * Writes to the dedicated `backup_v2` log channel (see config/logging.php) so V2
 * observability is isolated from the main app log. Every line carries a stable
 * context envelope (session id, site id, state, stage, error_code) making the
 * logs machine-parseable for reconciliation and incident review.
 *
 * Falls back gracefully to the default logger if the channel is not configured.
 */
final class BackupLogger
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private readonly array $context = [],
        private readonly string $channel = 'backup_v2',
    ) {}

    /**
     * Derive a logger bound to a specific session's identity. All subsequent
     * log lines automatically include this context.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public function forSession(
        string $kind,
        int|string|null $sessionId,
        ?int $siteId,
        ?string $state = null,
        ?string $stage = null,
        array $extra = [],
    ): self {
        return new self(array_merge($this->context, array_filter([
            'kind' => $kind,
            'session_id' => $sessionId,
            'site_id' => $siteId,
            'state' => $state,
            'stage' => $stage,
        ], static fn ($v) => $v !== null), $extra), $this->channel);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger()->log($level, $message, array_merge($this->context, $context));
    }

    private function logger(): LoggerInterface
    {
        $channels = config('logging.channels', []);

        if (is_array($channels) && array_key_exists($this->channel, $channels)) {
            return Log::channel($this->channel);
        }

        return Log::getFacadeRoot();
    }
}
