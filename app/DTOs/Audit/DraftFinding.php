<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * A drafted recommendation card, ready to persist into audit_cards. Port of
 * DraftFindingV2Payload (src/lib/ai/draft-v2.ts).
 */
final readonly class DraftFinding
{
    /**
     * @param  string  $team  DEV | CONTINUT
     * @param  string  $impact  MARE | MEDIU | MIC
     * @param  string  $effort  MARE | MEDIU | MIC
     * @param  list<string>  $checkIds  the NU_EXISTA checks this card covers
     * @param  array<string, mixed>  $payload  {table?, codeBlocks?, callouts?}
     */
    public function __construct(
        public string $title,
        public string $team,
        public string $impact,
        public string $effort,
        public string $severity,
        public string $recommendation,
        public string $evidenceText,
        public array $checkIds,
        public array $payload,
        public bool $needsVerification,
        public int $sortOrder,
    ) {}
}
