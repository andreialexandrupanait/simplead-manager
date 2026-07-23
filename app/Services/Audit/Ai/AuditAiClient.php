<?php

declare(strict_types=1);

namespace App\Services\Audit\Ai;

/**
 * The minimal Anthropic surface the audit AI needs: one messages call returning
 * the assistant message (content blocks + usage + stop_reason). Abstracted so it
 * can be faked in tests — the real API is never hit in CI, and no key is needed.
 */
interface AuditAiClient
{
    /**
     * @param  array<string, mixed>  $params  the Anthropic /v1/messages request body
     * @return array{content: list<array<string, mixed>>, usage: array{input_tokens: int, output_tokens: int}, stop_reason: string|null}
     */
    public function createMessage(array $params): array;
}
