<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RollbackPoint;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RollbackPoint>
 */
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
            'backup_reference' => fake()->uuid(),
            'status' => 'available',
            'expires_at' => now()->addDays(30),
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'available',
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'used',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expires_at' => now()->subDay(),
        ]);
    }

    public function plugin(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'plugin',
        ]);
    }

    public function theme(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'theme',
        ]);
    }
}
