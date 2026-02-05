<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\ErrorLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class ErrorLogFactory extends Factory
{
    protected $model = ErrorLog::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'error_hash' => fake()->unique()->sha256(),
            'level' => fake()->randomElement(['error', 'warning', 'notice', 'deprecated']),
            'message' => fake()->sentence(),
            'file_path' => '/var/www/html/wp-content/plugins/' . fake()->slug(2) . '.php',
            'line_number' => fake()->numberBetween(1, 500),
            'count' => fake()->numberBetween(1, 100),
            'first_seen_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'last_seen_at' => now()->subHours(fake()->numberBetween(1, 48)),
            'is_resolved' => false,
        ];
    }
}
