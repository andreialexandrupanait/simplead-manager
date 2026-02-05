<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\RollbackPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class RollbackPointFactory extends Factory
{
    protected $model = RollbackPoint::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(['plugin', 'theme', 'core']),
            'slug' => fake()->slug(2),
            'from_version' => fake()->numerify('#.#.#'),
            'to_version' => fake()->numerify('#.#.#'),
            'status' => 'available',
            'expires_at' => now()->addDays(fake()->numberBetween(1, 30)),
        ];
    }
}
