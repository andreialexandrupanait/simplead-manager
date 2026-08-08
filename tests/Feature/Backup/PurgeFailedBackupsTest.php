<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing out failed backups must not be able to take a restore point with it.
 *
 * The whole safety argument for this command is that it cannot touch a completed backup, so it can
 * be run without first checking what it would hit. That claim is only worth having if something
 * asserts it.
 */
class PurgeFailedBackupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_failed_rows_and_leaves_completed_ones(): void
    {
        $site = Site::factory()->create();

        // No destination and no file_path: a run that died before it wrote anything, which is what
        // most failures actually look like. A row that DID write objects is kept by purge() unless
        // storage confirms they are gone — deliberately, and covered by the retention suite.
        $failed = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Failed,
            'storage_destination_id' => null,
            'file_path' => null,
        ]);
        $cancelled = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Cancelled,
            'storage_destination_id' => null,
            'file_path' => null,
        ]);
        $completed = Backup::factory()->create(['site_id' => $site->id, 'status' => BackupStatus::Completed]);

        $this->artisan('backup:purge-failed --apply')->assertSuccessful();

        $this->assertDatabaseMissing('backups', ['id' => $failed->id]);
        $this->assertDatabaseMissing('backups', ['id' => $cancelled->id]);
        $this->assertDatabaseHas('backups', ['id' => $completed->id]);
    }

    /** A padlock is a person's decision, and it outranks a status. */
    public function test_a_locked_failed_backup_is_left_alone(): void
    {
        $site = Site::factory()->create();
        $locked = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Failed,
            'storage_destination_id' => null,
            'file_path' => null,
            'is_locked' => true,
        ]);

        $this->artisan('backup:purge-failed --apply')->assertSuccessful();

        $this->assertDatabaseHas('backups', ['id' => $locked->id]);
    }

    /** Dry-run is the default, because the alternative is an irreversible default. */
    public function test_nothing_is_deleted_without_apply(): void
    {
        $site = Site::factory()->create();
        $failed = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Failed,
            'storage_destination_id' => null,
            'file_path' => null,
        ]);

        $this->artisan('backup:purge-failed')->assertSuccessful();

        $this->assertDatabaseHas('backups', ['id' => $failed->id]);
    }
}
