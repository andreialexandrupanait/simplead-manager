<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * One PageSpeed Insights opportunity/insight audit. Port of PsiOpportunity from
 * the audit repo's collectors.
 */
final readonly class PsiOpportunity
{
    /**
     * @param  list<array{url: string, wastedBytes?: int|null, totalBytes?: int|null, reason?: string|null}>  $items
     */
    public function __construct(
        public string $id,
        public ?float $score,
        public ?int $savingsBytes,
        public ?float $savingsMs,
        public array $items = [],
    ) {}
}
