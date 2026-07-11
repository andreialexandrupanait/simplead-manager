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

    public function test_dispatcher_never_recovers_stuck_restores(): void
    {
        // P0-05: the dispatcher runs every minute and a healthy restore is
        // legitimately row-silent for up to 30 min inside a single HTTP call.
        // The dispatcher must NOT touch a silent restore or release its lock —
        // even one silent well past the old 30-min threshold. The single
        // recovery path is the 75-min ownership-checked command (PR #38).
        $backup = $this->makeStuckRestore(BackupStatus::InProgress, 45);

        (new BackupDispatcher)();

        // Untouched: still in progress, no failure written, no operator alert.
        $this->assertSame(BackupStatus::InProgress, $backup->fresh()->restore_status);
        Queue::assertNotPushed(NotifyRestoreFailed::class);
        Queue::assertNotPushed(\App\Jobs\RestoreBackup::class);
    }

    public function test_dispatcher_leaves_stuck_pending_restore_alone_too(): void
    {
        $backup = $this->makeStuckRestore(BackupStatus::Pending, 90);

        (new BackupDispatcher)();

        $this->assertSame(BackupStatus::Pending, $backup->fresh()->restore_status);
        Queue::assertNotPushed(NotifyRestoreFailed::class);
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
