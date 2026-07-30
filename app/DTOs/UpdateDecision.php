<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\UpdateRoute;

/**
 * Faza 6 — the pure result of the update-decision engine for a single plugin
 * update: which route it takes, the AI risk score behind it, and the ordered
 * human-readable reasons that produced the routing.
 *
 * {@see self::toArray()} is persisted into SafeUpdate.ai_risk_assessment and
 * {@see self::score()} feeds SafeUpdate.ai_risk_score.
 */
readonly class UpdateDecision
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public UpdateRoute $route,
        public int $score,
        public array $reasons,
    ) {}

    /** Score destined for SafeUpdate.ai_risk_score (0–100, higher = riskier). */
    public function score(): int
    {
        return $this->score;
    }

    /**
     * Shape written to SafeUpdate.ai_risk_assessment (jsonb).
     *
     * @return array{route: string, score: int, reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route->value,
            'score' => $this->score,
            'reasons' => array_values($this->reasons),
        ];
    }
}
