<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SafeUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SafeUpdateFactory extends Factory
{
    protected $model = SafeUpdate::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(['plugin', 'theme', 'core']),
            'name' => fake()->words(2, true),
            'slug' => fake()->slug(2),
            'from_version' => fake()->numerify('#.#.#'),
            'to_version' => fake()->numerify('#.#.#'),
            'status' => 'pending',
            'started_at' => now(),
        ];
    }
}
