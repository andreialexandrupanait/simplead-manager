<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IncidentResponse;
use App\Models\IncidentResponseAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IncidentResponseAction>
 */
class IncidentResponseActionFactory extends Factory
{
    protected $model = IncidentResponseAction::class;

    public function definition(): array
    {
        return [
            'incident_response_id' => IncidentResponse::factory(),
            'action_type' => fake()->randomElement([
                'run_diagnostic', 'health_check', 'flush_cache',
                'deactivate_plugin', 'activate_plugin', 'create_backup',
            ]),
            'tier' => fake()->randomElement(['playbook', 'ai_agent']),
            'parameters' => null,
            'result' => ['success' => true],
            'status' => 'success',
            'error_message' => null,
            'duration_ms' => fake()->numberBetween(100, 5000),
            'sequence' => 0,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'result' => ['success' => true],
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'result' => ['success' => false, 'error' => 'Action failed'],
            'error_message' => 'Action failed',
        ]);
    }

    public function playbook(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'playbook',
        ]);
    }

    public function aiAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'ai_agent',
        ]);
    }
}
