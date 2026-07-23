<?php

declare(strict_types=1);

namespace App\Services\Audit\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The production Anthropic client: POSTs to /v1/messages with the shared API key.
 * Non-streaming (our responses are small, ≤8k tokens); the audit repo streamed
 * only to dodge frontend timeouts. Mirrors the existing incident-response call.
 */
final class HttpAuditAiClient implements AuditAiClient
{
    public function createMessage(array $params): array
    {
        $apiKey = config('audit.ai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is missing — the audit AI tier is unavailable.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('audit.ai.anthropic_version'),
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('audit.ai.timeout', 120))
            ->post((string) config('audit.ai.base_url'), $params)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Anthropic returned an unexpected (non-JSON) response.');
        }

        /** @var array{content?: mixed, usage?: mixed, stop_reason?: mixed} $response */
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'content' => is_array($response['content'] ?? null) ? array_values($response['content']) : [],
            'usage' => [
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            ],
            'stop_reason' => isset($response['stop_reason']) ? (string) $response['stop_reason'] : null,
        ];
    }
}
