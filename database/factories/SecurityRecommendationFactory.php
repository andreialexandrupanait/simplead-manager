<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SecurityRecommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityRecommendationFactory extends Factory
{
    protected $model = SecurityRecommendation::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'key' => fake()->unique()->slug(2),
            'category' => fake()->randomElement(['authentication', 'file_security', 'database', 'server']),
            'title' => fake()->sentence(4),
            'status' => fake()->randomElement(['unchecked', 'pass', 'fail', 'ignored']),
            'can_auto_fix' => fake()->boolean(30),
            'last_checked_at' => now()->subHours(fake()->numberBetween(1, 72)),
        ];
    }
}
