<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\ResourceCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceCheckFactory extends Factory
{
    protected $model = ResourceCheck::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'cpu_usage' => fake()->randomFloat(2, 0, 100),
            'memory_used' => fake()->numberBetween(500000000, 8000000000),
            'memory_total' => 8000000000,
            'memory_percentage' => fake()->randomFloat(2, 10, 95),
            'disk_used' => fake()->numberBetween(10000000000, 90000000000),
            'disk_total' => 100000000000,
            'disk_percentage' => fake()->randomFloat(2, 10, 90),
            'load_average_1' => fake()->randomFloat(2, 0, 4),
            'load_average_5' => fake()->randomFloat(2, 0, 4),
            'load_average_15' => fake()->randomFloat(2, 0, 4),
            'is_available' => true,
            'checked_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
        ];
    }
}
