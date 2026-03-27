<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestoreBackupTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'closed',
        ]);
    }

    #[Test]
    public function restore_fails_without_storage_destination(): void
    {
        // Backup with no storage destination and a file path set
        $backup = Backup::factory()->for($this->site)->create([
            'storage_destination_id' => null,
            'file_path' => 'backups/some-backup.zip',
            'file_name' => 'some-backup.zip',
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::Pending,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup storage destination or file path is missing');

        (new RestoreBackup($backup))->handle();
    }

    #[Test]
    public function restore_updates_status_to_failed_on_error(): void
    {
        $destination = StorageDestination::factory()->create(['is_active' => true]);

        $backup = Backup::factory()->for($this->site)->for($destination)->create([
            'file_path' => 'backups/some-backup.zip',
            'file_name' => 'some-backup.zip',
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::Pending,
            'started_at' => now()->subMinutes(5),
        ]);

        try {
            (new RestoreBackup($backup))->handle();
        } catch (\Throwable) {
            // Expected — no real storage driver
        }

        $this->assertEquals(BackupStatus::Failed, $backup->fresh()->restore_status);
    }

    #[Test]
    public function restore_has_correct_memory_property(): void
    {
        $destination = StorageDestination::factory()->create();

        $backup = Backup::factory()->for($this->site)->for($destination)->create([
            'status' => BackupStatus::Completed,
        ]);

        $job = new RestoreBackup($backup);

        $this->assertSame(1024, $job->memory);
    }

    #[Test]
    public function restore_dispatched_on_backups_queue(): void
    {
        Queue::fake();

        $destination = StorageDestination::factory()->create();

        $backup = Backup::factory()->for($this->site)->for($destination)->create([
            'status' => BackupStatus::Completed,
        ]);

        RestoreBackup::dispatch($backup);

        Queue::assertPushedOn('backups', RestoreBackup::class);
    }

    #[Test]
    public function restore_unique_id_uses_backup_id(): void
    {
        $destination = StorageDestination::factory()->create();

        $backup = Backup::factory()->for($this->site)->for($destination)->create([
            'status' => BackupStatus::Completed,
        ]);

        $job = new RestoreBackup($backup);

        $this->assertSame('restore-'.$backup->id, $job->uniqueId());
    }
}
