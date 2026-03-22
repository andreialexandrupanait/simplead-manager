<?php

namespace Database\Factories;

use App\Models\StorageDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StorageDestination>
 */
class StorageDestinationFactory extends Factory
{
    protected $model = StorageDestination::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Local Storage', 'S3 Bucket', 'Google Drive', 'Dropbox', 'SFTP Server']).' '.fake()->numberBetween(1, 99),
            'type' => fake()->randomElement(['local', 's3', 'google_drive', 'dropbox', 'sftp']),
            'config' => [
                'path' => '/backups/'.fake()->slug(),
            ],
            'is_default' => false,
            'is_active' => true,
            'used_bytes' => fake()->numberBetween(0, 10_000_000_000),
            'quota_bytes' => fake()->optional(0.7)->numberBetween(10_000_000_000, 100_000_000_000),
            'last_tested_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
            'last_test_passed' => true,
            'last_test_error' => null,
        ];
    }

    /**
     * Indicate this is the default storage destination.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Indicate this storage destination is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate this is a local storage destination.
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local Storage',
            'type' => 'local',
            'config' => ['path' => '/backups'],
        ]);
    }

    /**
     * Indicate this is an S3 storage destination.
     */
    public function s3(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'AWS S3 Bucket',
            'type' => 's3',
            'config' => [
                'bucket' => 'my-backup-bucket',
                'region' => 'eu-central-1',
                'prefix' => 'backups/',
            ],
        ]);
    }

    /**
     * Indicate the last connectivity test failed.
     */
    public function testFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_tested_at' => now(),
            'last_test_passed' => false,
            'last_test_error' => 'Connection timed out after 30 seconds',
        ]);
    }
}
