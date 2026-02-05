<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\CoreFileCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

class CoreFileCheckFactory extends Factory
{
    protected $model = CoreFileCheck::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'wp_version' => fake()->randomElement(['6.4.2', '6.4.1', '6.3.2', '6.5.0']),
            'total_files' => fake()->numberBetween(1500, 2000),
            'modified_count' => fake()->numberBetween(0, 5),
            'missing_count' => fake()->numberBetween(0, 2),
            'unknown_count' => fake()->numberBetween(0, 10),
            'modified_files' => [],
            'missing_files' => [],
            'unknown_files' => [],
            'status' => 'completed',
            'checked_at' => now()->subHours(fake()->numberBetween(1, 48)),
        ];
    }
}
