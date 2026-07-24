<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckState;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditCheckResult>
 *
 * The 82 AuditCheck rows are seeded by the migration, so audit_check_id defaults
 * to a real seeded check (resolved at creation time).
 */
class AuditCheckResultFactory extends Factory
{
    protected $model = AuditCheckResult::class;

    public function definition(): array
    {
        return [
            'audit_id' => Audit::factory(),
            'audit_check_id' => fn (): ?int => AuditCheck::query()->value('id'),
            'state' => CheckState::Exista,
            'evidence' => ['note' => 'seeded by factory'],
            'state_set_by' => 'auto',
            'collected_at' => now(),
        ];
    }

    public function withState(CheckState|string|null $state): static
    {
        $value = $state instanceof CheckState ? $state : ($state === null ? null : CheckState::from($state));

        return $this->state(fn (): array => ['state' => $value]);
    }
}
