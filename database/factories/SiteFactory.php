<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $isUp = fake()->boolean(90);
        $healthScore = $isUp ? fake()->numberBetween(65, 100) : fake()->numberBetween(20, 50);

        return [
            "name" => fake()->company() . " Website",
            "url" => "https://" . fake()->domainName(),
            "client_id" => Client::factory(),
            "status" => "active",
            "health_score" => $healthScore,
            "wp_version" => fake()->randomElement(["6.4.2", "6.4.1", "6.3.2", "6.5.0"]),
            "php_version" => fake()->randomElement(["8.1", "8.2", "8.3"]),
            "server_software" => fake()->randomElement(["Apache/2.4", "Nginx/1.24", "LiteSpeed"]),
            "is_multisite" => fake()->boolean(15),
            "uptime_percentage" => $isUp ? fake()->randomFloat(2, 95, 100) : fake()->randomFloat(2, 80, 95),
            "is_up" => $isUp,
            "ssl_ok" => fake()->boolean(85),
            "ssl_expiry" => fake()->dateTimeBetween("now", "+1 year"),
            "pending_updates_count" => fake()->numberBetween(0, 12),
            "backup_ok" => fake()->boolean(70),
            "last_backup_at" => fake()->dateTimeBetween("-7 days", "now"),
        ];
    }
}
