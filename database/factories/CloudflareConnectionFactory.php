<?php

namespace Database\Factories;

use App\Models\CloudflareConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CloudflareConnection>
 */
class CloudflareConnectionFactory extends Factory
{
    protected $model = CloudflareConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'api_token' => 'cf-'.fake()->sha1(),
            'account_id' => fake()->uuid(),
            'account_email' => fake()->safeEmail(),
            'is_valid' => true,
            'last_validated_at' => now(),
        ];
    }

    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_valid' => false,
        ]);
    }
}
