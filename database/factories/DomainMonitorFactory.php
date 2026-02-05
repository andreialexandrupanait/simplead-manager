<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\DomainMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

class DomainMonitorFactory extends Factory
{
    protected $model = DomainMonitor::class;

    public function definition(): array
    {
        $daysRemaining = fake()->numberBetween(30, 365);

        return [
            'site_id' => Site::factory(),
            'domain' => fake()->domainName(),
            'tld' => fake()->randomElement(['com', 'net', 'org', 'io']),
            'registrar' => fake()->randomElement(['GoDaddy', 'Namecheap', 'Cloudflare', 'Google Domains']),
            'registered_at' => now()->subYears(fake()->numberBetween(1, 10)),
            'expires_at' => now()->addDays($daysRemaining),
            'days_remaining' => $daysRemaining,
            'nameservers' => ['ns1.example.com', 'ns2.example.com'],
            'status' => 'active',
            'alerts_enabled' => true,
            'warn_days' => 30,
            'last_checked_at' => now()->subHours(6),
        ];
    }
}
