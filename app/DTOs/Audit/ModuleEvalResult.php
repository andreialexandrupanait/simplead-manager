<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * The result of AI-evaluating one module's qualitative checks. Port of
 * ModuleEvalV2Result (src/lib/ai/evaluate-v2.ts).
 */
final readonly class ModuleEvalResult
{
    /**
     * @param  list<EvalResultV2>  $evaluations
     * @param  array{input_tokens: int, output_tokens: int}  $usage
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $evaluations,
        public array $usage,
        public array $warnings,
    ) {}
}
