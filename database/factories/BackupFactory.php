<?php

namespace Database\Factories;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Backup>
 */
class BackupFactory extends Factory
{
    protected $model = Backup::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'site_id' => Site::factory(),
            'storage_destination_id' => StorageDestination::factory(),
            'type' => fake()->randomElement(['full', 'files', 'database']),
            'trigger' => fake()->randomElement(['scheduled', 'manual', 'pre_update']),
            'status' => BackupStatus::Completed,
            'stage' => null,
            'progress_percent' => 100,
            'progress_message' => null,
            'error_message' => null,
            'file_path' => 'backups/'.fake()->uuid().'.zip',
            'file_name' => 'backup-'.fake()->date('Y-m-d').'.zip',
            'file_size' => fake()->numberBetween(10_000_000, 2_000_000_000),
            'checksum' => fake()->sha256(),
            'includes_files' => true,
            'includes_database' => true,
            'wp_version' => fake()->randomElement(['6.4.3', '6.5', '6.5.1', '6.5.2']),
            'php_version' => fake()->randomElement(['8.1', '8.2', '8.3']),
            'plugins_count' => fake()->numberBetween(5, 40),
            'themes_count' => fake()->numberBetween(1, 5),
            'db_size_mb' => fake()->randomFloat(2, 10, 500),
            'started_at' => $startedAt,
            'completed_at' => (clone $startedAt)->modify('+'.fake()->numberBetween(30, 600).' seconds'),
            'duration_seconds' => fake()->numberBetween(30, 600),
            'is_locked' => false,
            'lock_reason' => null,
            'expires_at' => fake()->optional()->dateTimeBetween('+7 days', '+90 days'),
            'notes' => fake()->optional(0.2)->sentence(),
        ];
    }

    /**
     * Indicate the backup is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BackupStatus::Completed,
            'progress_percent' => 100,
            'error_message' => null,
        ]);
    }

    /**
     * Indicate the backup failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BackupStatus::Failed,
            'progress_percent' => fake()->numberBetween(0, 80),
            'error_message' => fake()->randomElement([
                'Connection timed out while downloading files.',
                'Insufficient storage space on destination.',
                'Database export failed: access denied.',
                'Remote API returned 500 error.',
                'Maximum execution time exceeded.',
            ]),
            'completed_at' => null,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'checksum' => null,
        ]);
    }

    /**
     * Indicate the backup is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BackupStatus::Pending,
            'progress_percent' => 0,
            'started_at' => null,
            'completed_at' => null,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'checksum' => null,
            'duration_seconds' => null,
        ]);
    }

    /**
     * Indicate the backup is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BackupStatus::InProgress,
            'stage' => fake()->randomElement(['downloading_files', 'exporting_database', 'compressing', 'uploading']),
            'progress_percent' => fake()->numberBetween(10, 90),
            'progress_message' => 'Processing...',
            'completed_at' => null,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'checksum' => null,
            'duration_seconds' => null,
        ]);
    }

    /**
     * Indicate the backup is locked.
     */
    public function locked(string $reason = 'Important backup - do not delete'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_locked' => true,
            'lock_reason' => $reason,
            'expires_at' => null,
        ]);
    }

    /**
     * Indicate a database-only backup.
     */
    public function databaseOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'database',
            'includes_files' => false,
            'includes_database' => true,
        ]);
    }

    /**
     * Indicate a files-only backup.
     */
    public function filesOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'files',
            'includes_files' => true,
            'includes_database' => false,
        ]);
    }
}
