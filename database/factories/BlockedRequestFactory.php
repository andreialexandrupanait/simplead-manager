<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\IpRule;
use App\Models\BlockedRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlockedRequestFactory extends Factory
{
    protected $model = BlockedRequest::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'ip_rule_id' => IpRule::factory(),
            'ip_address' => fake()->ipv4(),
            'request_url' => '/' . fake()->slug(2),
            'user_agent' => fake()->userAgent(),
            'blocked_at' => now()->subMinutes(fake()->numberBetween(1, 1440)),
        ];
    }
}
