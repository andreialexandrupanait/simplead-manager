<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

use App\Enums\CheckState;

/**
 * The AI evaluation of one qualitative check: state + the cited evidence (what
 * the model saw). Port of EvalResultV2 (src/lib/ai/evaluate-v2.ts).
 */
final readonly class EvalResultV2
{
    public function __construct(
        public string $checkKey,
        public ?CheckState $state,
        public string $dovada,
        public bool $deVerificat,
    ) {}
}
