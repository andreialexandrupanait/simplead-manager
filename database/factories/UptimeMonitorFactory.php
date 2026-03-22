<?php

namespace Database\Factories;

use App\Enums\MonitorState;
use App\Enums\MonitorStatus;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UptimeMonitor>
 */
class UptimeMonitorFactory extends Factory
{
    protected $model = UptimeMonitor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => 'http',
            'url' => fake()->url(),
            'timeout' => 30,
            'http_method' => 'GET',
            'http_headers' => null,
            'http_body' => null,
            'accepted_status_codes' => [200, 201, 301, 302],
            'follow_redirects' => true,
            'keyword' => null,
            'keyword_type' => null,
            'keyword_case_sensitive' => false,
            'check_ssl' => true,
            'ssl_expiry_threshold' => 14,
            'alert_after_failures' => 3,
            'alert_contacts' => null,
            'consecutive_failures' => 0,
            'status' => MonitorStatus::Active,
            'current_state' => MonitorState::Up,
            'last_checked_at' => fake()->dateTimeBetween('-5 minutes', 'now'),
            'next_check_at' => fake()->dateTimeBetween('now', '+5 minutes'),
            'last_state_change_at' => fake()->dateTimeBetween('-30 days', '-1 hour'),
            'uptime_24h' => fake()->randomFloat(2, 99.0, 100.0),
            'uptime_7d' => fake()->randomFloat(2, 98.0, 100.0),
            'uptime_30d' => fake()->randomFloat(2, 97.0, 100.0),
            'uptime_365d' => fake()->randomFloat(2, 95.0, 100.0),
            'avg_response_time' => fake()->numberBetween(100, 2000),
            'last_response_time' => fake()->numberBetween(50, 3000),
            'last_failure_reason' => null,
            'interval_minutes' => fake()->randomElement([1, 3, 5, 10, 15]),
        ];
    }

    /**
     * Indicate the monitor is active and site is up.
     */
    public function up(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MonitorStatus::Active,
            'current_state' => MonitorState::Up,
            'consecutive_failures' => 0,
            'last_failure_reason' => null,
        ]);
    }

    /**
     * Indicate the site is down.
     */
    public function down(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MonitorStatus::Active,
            'current_state' => MonitorState::Down,
            'consecutive_failures' => fake()->numberBetween(3, 20),
            'last_failure_reason' => fake()->randomElement([
                'Connection timed out',
                'HTTP 500 Internal Server Error',
                'HTTP 503 Service Unavailable',
                'DNS resolution failed',
                'SSL handshake failed',
            ]),
            'last_state_change_at' => fake()->dateTimeBetween('-2 hours', '-5 minutes'),
        ]);
    }

    /**
     * Indicate the site is degraded.
     */
    public function degraded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MonitorStatus::Active,
            'current_state' => MonitorState::Degraded,
            'avg_response_time' => fake()->numberBetween(3000, 10000),
            'last_response_time' => fake()->numberBetween(3000, 15000),
        ]);
    }

    /**
     * Indicate the monitor is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MonitorStatus::Paused,
            'current_state' => MonitorState::Unknown,
        ]);
    }

    /**
     * Indicate the monitor checks for a keyword.
     */
    public function withKeyword(string $keyword = 'Welcome'): static
    {
        return $this->state(fn (array $attributes) => [
            'keyword' => $keyword,
            'keyword_type' => 'contains',
            'keyword_case_sensitive' => false,
        ]);
    }
}
