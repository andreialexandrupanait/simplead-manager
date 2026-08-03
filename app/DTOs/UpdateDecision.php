<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\UpdateRoute;

/**
 * Faza 6 — the pure result of the update-decision engine for a single plugin
 * update: which route it takes and the ordered human-readable reasons that
 * produced the routing.
 */
readonly class UpdateDecision
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public UpdateRoute $route,
        public array $reasons,
    ) {}

    /**
     * @return array{route: string, reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route->value,
            'reasons' => $this->reasons,
        ];
    }
}
