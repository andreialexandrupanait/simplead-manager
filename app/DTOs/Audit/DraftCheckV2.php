<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

use App\Enums\CheckState;

/**
 * One v2 check of a module, with its evaluated state + collected evidence, as
 * input to AI card drafting. Port of DraftV2Check (src/lib/ai/draft-v2.ts).
 */
final readonly class DraftCheckV2
{
    /**
     * @param  mixed  $evidence  the collected evidence (its `affected[].url` feeds the per-URL table)
     */
    public function __construct(
        public string $key,
        public string $question,
        public ?CheckState $state,
        public ?string $subsection,
        public ?string $subsectionName,
        public ?string $team,
        public ?string $recommendationTemplate,
        public mixed $evidence,
    ) {}
}
