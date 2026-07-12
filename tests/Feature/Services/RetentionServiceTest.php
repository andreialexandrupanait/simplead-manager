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

        // These assertions verify real deletion behaviour, so disable the
        // safe-rollout log-only guard (default TRUE). A dedicated test below
        // covers the dry-run path itself.
        config(['backups.retention_dry_run' => false]);

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

    public function test_out_of_count_orphaned_incremental_is_removed_by_chain_policy(): void
    {
        // An orphaned incremental (parent gone) forms its own single-member
        // chain. Under count=1 with a newer full present, that old orphan chain
        // is beyond the retention count and is pruned.
        $this->createBackupConfig('count', 1);

        $newFull = $this->createBackup(['type' => 'full', 'created_at' => now()->subDay()]);
        $orphan = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => null,
            'created_at' => now()->subDays(20),
        ]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $newFull->id]);
        $this->assertDatabaseMissing('backups', ['id' => $orphan->id]);
    }

    public function test_recent_orphaned_incremental_is_protected_in_days_mode(): void
    {
        // P0-03: the age-gated orphan sweep must NEVER destroy a recent restore
        // point. A fresh orphan (within the window) survives; an old one is swept.
        $this->createBackupConfig('days', 7);

        $freshOrphan = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => null,
            'created_at' => now()->subDay(),
        ]);
        $oldOrphan = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => null,
            'created_at' => now()->subDays(20),
        ]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $freshOrphan->id]);
        $this->assertDatabaseMissing('backups', ['id' => $oldOrphan->id]);
    }

    public function test_days_mode_old_full_with_fresh_incremental_both_survive(): void
    {
        // THE P0-03 data-loss regression: weekly full + daily incrementals with
        // 7-day retention. The base full has crossed the cutoff but a fresh
        // incremental still descends from it — BOTH must survive, because the
        // chain's newest member is inside the window.
        $this->createBackupConfig('days', 7);

        $oldFull = $this->createBackup(['type' => 'full', 'created_at' => now()->subDays(9)]);
        $freshIncremental = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => $oldFull->id,
            'created_at' => now()->subDay(),
        ]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $oldFull->id]);
        $this->assertDatabaseHas('backups', ['id' => $freshIncremental->id]);
    }

    public function test_count_mode_old_full_with_fresh_incremental_both_survive(): void
    {
        // Same data-loss shape in count mode: the chain sorts by its newest
        // member (the fresh incremental), so it stays within the kept count and
        // its base full is not stripped out from under it.
        $this->createBackupConfig('count', 1);

        $oldFull = $this->createBackup(['type' => 'full', 'created_at' => now()->subDays(30)]);
        $freshIncremental = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => $oldFull->id,
            'created_at' => now()->subDay(),
        ]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $oldFull->id]);
        $this->assertDatabaseHas('backups', ['id' => $freshIncremental->id]);
    }

    public function test_days_mode_fully_expired_chain_is_deleted(): void
    {
        // Control: when the NEWEST member is also outside the window, the whole
        // chain is correctly deleted.
        $this->createBackupConfig('days', 7);

        $oldFull = $this->createBackup(['type' => 'full', 'created_at' => now()->subDays(30)]);
        $oldIncremental = $this->createBackup([
            'type' => 'incremental',
            'parent_backup_id' => $oldFull->id,
            'created_at' => now()->subDays(20),
        ]);
        $fresh = $this->createBackup(['type' => 'full', 'created_at' => now()->subDay()]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseMissing('backups', ['id' => $oldFull->id]);
        $this->assertDatabaseMissing('backups', ['id' => $oldIncremental->id]);
        $this->assertDatabaseHas('backups', ['id' => $fresh->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        // Safe-rollout guard: with the log-only flag on, an over-count chain that
        // WOULD be deleted is left fully intact.
        config(['backups.retention_dry_run' => true]);
        $this->createBackupConfig('count', 1);

        $old = $this->createBackup(['type' => 'full', 'created_at' => now()->subDays(10)]);
        $new = $this->createBackup(['type' => 'full', 'created_at' => now()->subDay()]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $old->id]);
        $this->assertDatabaseHas('backups', ['id' => $new->id]);
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

    private function createPreUpdateLock(array $overrides = []): Backup
    {
        return Backup::factory()->completed()->create(array_merge([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'type' => 'full',
            'trigger' => 'pre_update',
            'is_locked' => true,
            'lock_reason' => 'pre-update',
            'format' => 'v2-zip',
            'file_path' => 'backups/'.fake()->uuid().'.zip',
            'file_size' => 1000,
            'replicas' => [],
        ], $overrides));
    }

    public function test_expired_pre_update_lock_is_reclaimed(): void
    {
        // P2-30: a pre-update backup older than the lock window becomes eligible
        // for cleanup, provided the site keeps another restore point.
        config(['backups.pre_update_lock_days' => 7]);
        $this->createBackupConfig('count', 5);

        $recent = $this->createBackup(['created_at' => now()->subDay()]);
        $expiredLock = $this->createPreUpdateLock(['created_at' => now()->subDays(10)]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseMissing('backups', ['id' => $expiredLock->id]);
        $this->assertDatabaseHas('backups', ['id' => $recent->id]);
    }

    public function test_fresh_pre_update_lock_is_retained(): void
    {
        // Within the protection window the pre-update lock is untouchable.
        config(['backups.pre_update_lock_days' => 7]);
        $this->createBackupConfig('count', 5);

        $recent = $this->createBackup(['created_at' => now()->subDay()]);
        $freshLock = $this->createPreUpdateLock(['created_at' => now()->subDays(2)]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $freshLock->id]);
        $this->assertDatabaseHas('backups', ['id' => $recent->id]);
    }

    public function test_expired_pre_update_lock_kept_when_only_backup(): void
    {
        // Never delete the site's last remaining restore point, even if expired.
        config(['backups.pre_update_lock_days' => 7]);
        $this->createBackupConfig('count', 5);

        $onlyLock = $this->createPreUpdateLock(['created_at' => now()->subDays(30)]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $onlyLock->id]);
    }

    public function test_manual_lock_is_never_reclaimed_by_pre_update_sweep(): void
    {
        // A user-applied lock (any reason other than 'pre-update') is protected.
        config(['backups.pre_update_lock_days' => 7]);
        $this->createBackupConfig('count', 5);

        $recent = $this->createBackup(['created_at' => now()->subDay()]);
        $manualLock = $this->createPreUpdateLock([
            'created_at' => now()->subDays(30),
            'trigger' => 'manual',
            'lock_reason' => 'Keep forever',
        ]);

        $this->service->apply($this->site, $this->destination);

        $this->assertDatabaseHas('backups', ['id' => $manualLock->id]);
        $this->assertDatabaseHas('backups', ['id' => $recent->id]);
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
