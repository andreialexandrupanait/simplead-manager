<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['backup', 'update', 'uptime', 'security', 'ssl', 'domain', 'performance', 'system']),
            'severity' => fake()->randomElement(['info', 'warning', 'error', 'success']),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'metadata' => null,
            'icon' => fake()->optional()->randomElement(['check-circle', 'alert-triangle', 'x-circle', 'info', 'shield', 'download']),
            'url' => fake()->optional(0.3)->url(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Indicate an info-level log entry.
     */
    public function info(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'info',
            'icon' => 'info',
        ]);
    }

    /**
     * Indicate a warning-level log entry.
     */
    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'warning',
            'icon' => 'alert-triangle',
        ]);
    }

    /**
     * Indicate an error-level log entry.
     */
    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'error',
            'icon' => 'x-circle',
        ]);
    }

    /**
     * Indicate a success-level log entry.
     */
    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'success',
            'icon' => 'check-circle',
        ]);
    }

    /**
     * Indicate a backup-type log entry.
     */
    public function backup(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'backup',
            'title' => fake()->randomElement(['Backup completed successfully', 'Backup failed', 'Backup started']),
        ]);
    }

    /**
     * Indicate an update-type log entry.
     */
    public function update(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'update',
            'title' => fake()->randomElement(['Plugin updated', 'Theme updated', 'WordPress core updated']),
        ]);
    }

    /**
     * Indicate an uptime-type log entry.
     */
    public function uptime(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'uptime',
            'title' => fake()->randomElement(['Site went down', 'Site is back up', 'Downtime detected']),
        ]);
    }

    /**
     * Indicate a security-type log entry.
     */
    public function security(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'security',
            'title' => fake()->randomElement(['Security scan completed', 'Vulnerability detected', 'Security issue resolved']),
        ]);
    }

    /**
     * Set metadata on the log entry.
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create an entry without a user (system-generated).
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'type' => 'system',
        ]);
    }
}
