<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SafeUpdate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SafeUpdate> */
class SafeUpdateFactory extends Factory
{
    protected $model = SafeUpdate::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(['plugin', 'theme', 'core']),
            'slug' => fake()->slug(2),
            'name' => fake()->words(3, true),
            'from_version' => fake()->semver(),
            'to_version' => fake()->semver(),
            'status' => 'pending',
            'health_check_results' => null,
            'error_message' => null,
            'auto_rollback' => true,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_message' => 'Update failed: plugin not compatible',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'updating',
            'started_at' => now()->subMinutes(2),
        ]);
    }
}
