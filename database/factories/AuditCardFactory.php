<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditTeam;
use App\Models\Audit;
use App\Models\AuditCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditCard>
 */
class AuditCardFactory extends Factory
{
    protected $model = AuditCard::class;

    public function definition(): array
    {
        return [
            'audit_id' => Audit::factory(),
            'title' => fake()->sentence(4),
            'team' => AuditTeam::Dev,
            'impact' => fake()->randomElement(['mare', 'mediu', 'mic']),
            'effort' => fake()->randomElement(['mare', 'mediu', 'mic']),
            'recommendation' => fake()->paragraph(),
            'evidence_text' => null,
            'check_ids' => ['2.7.1'],
            'payload' => null,
            'validation' => 'DRAFT_AI',
            'implementation' => 'NEIMPLEMENTAT',
            'needs_verification' => false,
            'auto_approved' => false,
            'sort_order' => 0,
        ];
    }

    public function validation(string $validation): static
    {
        return $this->state(fn (): array => ['validation' => $validation]);
    }

    public function needsVerification(bool $value = true): static
    {
        return $this->state(fn (): array => ['needs_verification' => $value]);
    }
}
