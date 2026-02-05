<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\CloudflareConnection;
use App\Models\SiteCloudflare;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteCloudflareFactory extends Factory
{
    protected $model = SiteCloudflare::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'cloudflare_connection_id' => CloudflareConnection::factory(),
            'zone_id' => fake()->sha1(),
            'zone_name' => fake()->domainName(),
            'plan_type' => fake()->randomElement(['free', 'pro', 'business', 'enterprise']),
            'status' => 'active',
            'is_paused' => false,
            'ssl_mode' => fake()->randomElement(['flexible', 'full', 'strict']),
            'cache_level' => fake()->randomElement(['basic', 'simplified', 'aggressive']),
            'connected_at' => now()->subDays(fake()->numberBetween(1, 90)),
        ];
    }
}
