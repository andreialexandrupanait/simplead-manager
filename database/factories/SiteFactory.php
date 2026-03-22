<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'name' => fake()->company().' Website',
            'url' => 'https://'.$domain,
            'user_id' => User::factory(),
            'client_id' => null,
            'status' => 'active',
            'health_score' => fake()->numberBetween(60, 100),
            'type' => 'wordpress',
            'is_connected' => true,
            'last_synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
            'wp_version' => fake()->randomElement(['6.4.3', '6.5', '6.5.1', '6.5.2', '6.6']),
            'php_version' => fake()->randomElement(['8.1', '8.2', '8.3']),
            'server_software' => fake()->randomElement(['Apache/2.4', 'nginx/1.24', 'LiteSpeed']),
            'is_multisite' => false,
            'uptime_percentage' => fake()->randomFloat(2, 98.0, 100.0),
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => fake()->dateTimeBetween('+30 days', '+365 days'),
            'pending_updates_count' => fake()->numberBetween(0, 5),
            'backup_ok' => true,
            'last_backup_at' => fake()->dateTimeBetween('-1 day', 'now'),
            'notes' => fake()->optional(0.3)->sentence(),
            'db_size_mb' => fake()->randomFloat(2, 10, 500),
            'uploads_size_mb' => fake()->randomFloat(2, 50, 5000),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Indicate the site belongs to a client.
     */
    public function forClient(?Client $client = null): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $client ?? Client::factory(),
        ]);
    }

    /**
     * Indicate the site is healthy (score >= 80, up, SSL OK).
     */
    public function healthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_score' => fake()->numberBetween(80, 100),
            'is_up' => true,
            'ssl_ok' => true,
            'backup_ok' => true,
            'is_connected' => true,
            'uptime_percentage' => fake()->randomFloat(2, 99.5, 100.0),
            'pending_updates_count' => 0,
        ]);
    }

    /**
     * Indicate the site has warnings (score 50-79).
     */
    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_score' => fake()->numberBetween(50, 79),
            'is_up' => true,
            'pending_updates_count' => fake()->numberBetween(3, 10),
        ]);
    }

    /**
     * Indicate the site is in critical state (score < 50 or down).
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_score' => fake()->numberBetween(0, 49),
            'is_up' => false,
            'ssl_ok' => false,
            'backup_ok' => false,
            'uptime_percentage' => fake()->randomFloat(2, 80.0, 95.0),
        ]);
    }

    /**
     * Indicate the site is disconnected.
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_connected' => false,
            'last_synced_at' => fake()->dateTimeBetween('-30 days', '-7 days'),
        ]);
    }

    /**
     * Indicate the site is down.
     */
    public function down(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_up' => false,
            'uptime_percentage' => fake()->randomFloat(2, 85.0, 98.0),
        ]);
    }

    /**
     * Indicate the site has pending updates.
     */
    public function withPendingUpdates(int $count = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'pending_updates_count' => $count,
        ]);
    }

    /**
     * Indicate the site's SSL is expiring soon.
     */
    public function sslExpiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_ok' => false,
            'ssl_expiry' => fake()->dateTimeBetween('now', '+14 days'),
        ]);
    }

    /**
     * Indicate the site is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
