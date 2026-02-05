<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\SiteCloudflare;
use App\Models\CloudflareCachePurge;
use Illuminate\Database\Eloquent\Factories\Factory;

class CloudflareCachePurgeFactory extends Factory
{
    protected $model = CloudflareCachePurge::class;

    public function definition(): array
    {
        return [
            'site_cloudflare_id' => SiteCloudflare::factory(),
            'type' => fake()->randomElement(['everything', 'urls', 'tags', 'prefixes']),
            'targets' => null,
            'purged_by' => User::factory(),
            'purged_at' => now()->subMinutes(fake()->numberBetween(1, 1440)),
        ];
    }
}
