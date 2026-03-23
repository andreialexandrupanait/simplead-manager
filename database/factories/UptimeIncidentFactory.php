<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UptimeIncident>
 */
class UptimeIncidentFactory extends Factory
{
    protected $model = UptimeIncident::class;

    /**
     * Define the model's default state — an ongoing incident.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => UptimeMonitor::factory(),
            'status' => 'ongoing',
            'cause' => fake()->randomElement([
                'HTTP 500',
                'HTTP 503',
                'Connection timed out',
                'DNS resolution failed',
            ]),
            'started_at' => fake()->dateTimeBetween('-2 hours', '-5 minutes'),
            'resolved_at' => null,
            'notified_via' => null,
            'notified_at' => null,
        ];
    }

    /**
     * Indicate the incident has been resolved.
     */
    public function resolved(): static
    {
        return $this->state(function (array $attributes) {
            $startedAt = $attributes['started_at'] instanceof \DateTime
                ? $attributes['started_at']
                : new \DateTime($attributes['started_at']);

            return [
                'status' => 'resolved',
                'resolved_at' => fake()->dateTimeBetween($startedAt, 'now'),
            ];
        });
    }
}
