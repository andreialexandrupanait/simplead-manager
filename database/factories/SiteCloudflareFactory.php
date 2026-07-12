<?php

namespace Database\Factories;

use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteCloudflare>
 */
class SiteCloudflareFactory extends Factory
{
    protected $model = SiteCloudflare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'cloudflare_connection_id' => CloudflareConnection::factory(),
            'zone_id' => str_repeat('a', 32),
            'zone_name' => fake()->domainName(),
            'plan_type' => 'free',
            'status' => 'active',
            'is_paused' => false,
            'ssl_mode' => 'full',
            'cache_level' => 'aggressive',
            'connected_at' => now(),
            'is_active' => true,
            'interval_minutes' => 360,
        ];
    }
}
