<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * One qualitative check of a module, still state=null, subject to AI evaluation.
 * Port of EvalCheckV2 (src/lib/ai/evaluate-v2.ts).
 */
final readonly class EvalCheckV2
{
    /**
     * @param  list<string>  $sourceTypes  source types (manual/web/ai/...) — context for the model
     * @param  mixed  $existingEvidence  evidence already collected by SF/PSI (may be null)
     */
    public function __construct(
        public string $key,
        public string $question,
        public ?string $subsection,
        public ?string $subsectionName,
        public ?string $team,
        public array $sourceTypes,
        public ?string $recommendationTemplate,
        public mixed $existingEvidence,
    ) {}
}
