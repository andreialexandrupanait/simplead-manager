<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'website' => fake()->url(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'vat_number' => fake()->optional()->bothify('??########'),
            'registration_number' => fake()->optional()->bothify('J##/####/####'),
            'notes' => fake()->optional()->sentence(),
            'status' => 'active',
        ];
    }

    /**
     * Indicate the client is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate the client is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }
}
