<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\WpAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class WpAuditLogFactory extends Factory
{
    protected $model = WpAuditLog::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'wp_user_id' => fake()->numberBetween(1, 10),
            'wp_username' => fake()->userName(),
            'user_role' => fake()->randomElement(['administrator', 'editor', 'author', 'subscriber']),
            'action_type' => fake()->randomElement(['login', 'logout', 'post_edit', 'plugin_activate', 'option_update']),
            'object_type' => fake()->optional()->randomElement(['post', 'page', 'plugin', 'option']),
            'object_id' => (string) fake()->optional()->numberBetween(1, 100),
            'object_title' => fake()->optional()->sentence(3),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'action_at' => now()->subHours(fake()->numberBetween(1, 168)),
        ];
    }
}
