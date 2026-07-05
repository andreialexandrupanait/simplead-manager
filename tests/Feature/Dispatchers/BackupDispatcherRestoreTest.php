<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\BackupDispatcher;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\NotifyRestoreFailed;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class BackupDispatcherRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        $this->site = Site::factory()->create(['is_connected' => true]);
    }

    private function makeStuckRestore(BackupStatus $restoreStatus, int $minutesAgo): Backup
    {
        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::Completed,
            'restore_status' => $restoreStatus,
        ]);

        // updated_at drives staleness detection; bypass model touching.
        Backup::withoutTimestamps(fn () => $backup->forceFill([
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->save());

        return $backup;
    }

    public function test_stuck_in_progress_restore_is_marked_failed_and_alerted_without_retry(): void
    {
        $backup = $this->makeStuckRestore(BackupStatus::InProgress, 45);

        (new BackupDispatcher)();

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Failed, $fresh->restore_status);
        $this->assertStringContainsString('Stuck restore', $fresh->restore_error_message);

        Queue::assertPushed(NotifyRestoreFailed::class);
        // No blind re-run of a half-applied restore.
        Queue::assertNotPushed(\App\Jobs\RestoreBackup::class);
    }

    public function test_fresh_in_progress_restore_is_left_alone(): void
    {
        $backup = $this->makeStuckRestore(BackupStatus::InProgress, 10);

        (new BackupDispatcher)();

        $this->assertSame(BackupStatus::InProgress, $backup->fresh()->restore_status);
        Queue::assertNotPushed(NotifyRestoreFailed::class);
    }

    public function test_stuck_pending_restore_is_cancelled_after_an_hour(): void
    {
        $backup = $this->makeStuckRestore(BackupStatus::Pending, 90);

        (new BackupDispatcher)();

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
        Queue::assertPushed(NotifyRestoreFailed::class);
    }

    public function test_scheduled_backup_is_not_dispatched_while_a_restore_is_running(): void
    {
        $this->makeStuckRestore(BackupStatus::InProgress, 5);

        BackupConfig::factory()->create([
            'site_id' => $this->site->id,
            'is_enabled' => true,
            'next_backup_at' => now()->subMinute(),
        ]);

        (new BackupDispatcher)();

        Queue::assertNotPushed(CreateBackup::class);
        Queue::assertNotPushed(CreateIncrementalBackup::class);
    }

    public function test_scheduled_backup_dispatches_normally_without_active_restore(): void
    {
        BackupConfig::factory()->create([
            'site_id' => $this->site->id,
            'is_enabled' => true,
            'next_backup_at' => now()->subMinute(),
        ]);

        (new BackupDispatcher)();

        $this->assertTrue(
            Queue::pushed(CreateBackup::class)->isNotEmpty()
            || Queue::pushed(CreateIncrementalBackup::class)->isNotEmpty(),
            'Expected a scheduled backup job to be dispatched.'
        );
    }
}
