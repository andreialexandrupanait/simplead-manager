<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RetentionService $service;

    private Site $site;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RetentionService;

        $this->destination = StorageDestination::factory()->local()->create([
            'config' => ['path' => sys_get_temp_dir().'/test-retention-'.uniqid()],
            'used_bytes' => 0,
        ]);

        $this->site = Site::factory()->create();
    }

    private function createBackupConfig(string $type = 'count', int $value = 3): BackupConfig
    {
        return BackupConfig::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'retention_type' => $type,
            'retention_value' => $value,
        ]);
    }

    private function createBackup(array $overrides = []): Backup
    {
        return Backup::factory()->completed()->create(array_merge([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'format' => 'v2-zip',
            'file_path' => 'backups/'.fake()->uuid().'.zip',
            'file_size' => 1000,
            'replicas' => [],
        ], $overrides));
    }

    public function test_no_action_without_backup_config(): void
    {
        $backup = $this->createBackup();

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
    }

    public function test_count_retention_keeps_newest_chains(): void
    {
        $this->createBackupConfig('count', 2);

        $old = $this->createBackup(['created_at' => now()->subDays(10)]);
        $mid = $this->createBackup(['created_at' => now()->subDays(5)]);
        $new = $this->createBackup(['created_at' => now()->subDay()]);

        $this->service->apply($this->site, $this->destination);

        // Newest 2 kept, oldest deleted
        $this->assertDatabaseHas('backups', ['id' => $new->id]);
        $this->assertDatabaseHas('backups', ['id' => $mid->id]);
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
    }

    public function test_days_retention_deletes_older_backups(): void
    {
        $this->createBackupConfig('days', 7);

        $old = $this->createBackup(['created_at' => now()->subDays(10)]);
        $recent = $this->createBackup(['created_at' => now()->subDays(3)]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $recent->id]);
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
    }

    public function test_locked_backups_are_protected(): void
    {
        $this->createBackupConfig('count', 1);

        $locked = $this->createBackup([
            'created_at' => now()->subDays(10),
            'is_locked' => true,
            'lock_reason' => 'Important',
        ]);
        $recent = $this->createBackup(['created_at' => now()->subDay()]);

        $this->service->apply($this->site, $this->destination);

        // Locked backup should NOT be touched (not even loaded by query)
        $this->assertDatabaseHas('backups', ['id' => $locked->id]);
        $this->assertDatabaseHas('backups', ['id' => $recent->id]);
    }

    public function test_incremental_backups_deleted_with_parent_chain(): void
    {
        $this->createBackupConfig('count', 1);

        $oldFull = $this->createBackup(['created_at' => now()->subDays(10), 'type' => 'full']);
        $oldIncremental = $this->createBackup([
            'created_at' => now()->subDays(9),
            'type' => 'incremental',
            'parent_backup_id' => $oldFull->id,
        ]);
        $newFull = $this->createBackup(['created_at' => now()->subDay(), 'type' => 'full']);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $newFull->id]);
        $this->assertDatabaseMissing('backups', ['id' => $oldFull->id]);
        $this->assertDatabaseMissing('backups', ['id' => $oldIncremental->id]);
    }

    public function test_orphaned_incrementals_are_cleaned_up(): void
    {
        $this->createBackupConfig('count', 10);

        // Orphaned incremental: type=incremental, parent_backup_id=null
        $orphan = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => null,
            'created_at' => now()->subDays(5),
        ]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseMissing('backups', ['id' => $orphan->id]);
    }

    public function test_failed_backups_are_not_affected(): void
    {
        $this->createBackupConfig('count', 1);

        $failed = Backup::factory()->failed()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'created_at' => now()->subDays(10),
        ]);
        $completed = $this->createBackup(['created_at' => now()->subDay()]);

        $this->service->apply($this->site, $this->destination);

        // Failed backups are not picked up by the completed query
        $this->assertDatabaseHas('backups', ['id' => $failed->id]);
        $this->assertDatabaseHas('backups', ['id' => $completed->id]);
    }

    public function test_count_retention_with_zero_excess(): void
    {
        $this->createBackupConfig('count', 5);

        $b1 = $this->createBackup(['created_at' => now()->subDays(3)]);
        $b2 = $this->createBackup(['created_at' => now()->subDays(2)]);
        $b3 = $this->createBackup(['created_at' => now()->subDay()]);

        $this->service->apply($this->site, $this->destination);

        // All 3 kept — below the limit of 5
        $this->assertDatabaseHas('backups', ['id' => $b1->id]);
        $this->assertDatabaseHas('backups', ['id' => $b2->id]);
        $this->assertDatabaseHas('backups', ['id' => $b3->id]);
    }
}
