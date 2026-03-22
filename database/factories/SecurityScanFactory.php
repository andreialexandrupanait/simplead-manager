<?php

namespace Database\Factories;

use App\Models\SecurityScan;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecurityScan>
 */
class SecurityScanFactory extends Factory
{
    protected $model = SecurityScan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'score' => fake()->numberBetween(50, 100),
            'critical_count' => fake()->numberBetween(0, 2),
            'high_count' => fake()->numberBetween(0, 5),
            'medium_count' => fake()->numberBetween(0, 10),
            'low_count' => fake()->numberBetween(0, 15),
            'scan_duration' => fake()->numberBetween(5, 120),
            'scanned_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Indicate the scan result is clean (high score, no issues).
     */
    public function clean(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => fake()->numberBetween(90, 100),
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => fake()->numberBetween(0, 2),
        ]);
    }

    /**
     * Indicate the scan found critical issues.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => fake()->numberBetween(0, 49),
            'critical_count' => fake()->numberBetween(1, 5),
            'high_count' => fake()->numberBetween(2, 8),
            'medium_count' => fake()->numberBetween(3, 10),
            'low_count' => fake()->numberBetween(2, 10),
        ]);
    }

    /**
     * Indicate the scan needs attention (moderate score).
     */
    public function needsAttention(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => fake()->numberBetween(50, 79),
            'critical_count' => 0,
            'high_count' => fake()->numberBetween(1, 3),
            'medium_count' => fake()->numberBetween(2, 8),
            'low_count' => fake()->numberBetween(1, 5),
        ]);
    }
}
