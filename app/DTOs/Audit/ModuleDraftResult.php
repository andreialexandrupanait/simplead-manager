<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * The result of AI-drafting one module's recommendation cards. Port of
 * ModuleDraftV2Result (src/lib/ai/draft-v2.ts).
 */
final readonly class ModuleDraftResult
{
    /**
     * @param  list<DraftFinding>  $findings
     * @param  array{input_tokens: int, output_tokens: int}  $usage
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $findings,
        public array $usage,
        public array $warnings,
        public bool $refused,
    ) {}
}
