<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

use App\Enums\CheckState;

/**
 * The evaluation of one v2 check: a state (or null = left to manual review) plus
 * cited evidence. Port of V2Eval (src/lib/evaluation/v2/evaluators.ts).
 *
 * The anti-fabrication guarantee (Faza D3c/D3d) rests on `evidence` — a verdict
 * without cited evidence is rejected downstream.
 */
final readonly class V2Eval
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public ?CheckState $state,
        public array $evidence,
    ) {}
}
