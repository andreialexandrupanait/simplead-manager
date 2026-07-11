<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\RecoverStuckRestores;
use App\Enums\BackupStatus;
use App\Jobs\NotifyRestoreFailed;
use App\Models\Backup;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverStuckRestoresTest extends TestCase
{
    use RefreshDatabase;

    private function staleRestore(): Backup
    {
        $backup = Backup::factory()->create(['restore_status' => BackupStatus::InProgress]);

        DB::table('backups')->where('id', $backup->id)->update([
            'updated_at' => now()->subMinutes(RecoverStuckRestores::STALE_AFTER_MINUTES + 10),
        ]);

        return $backup->fresh();
    }

    public function test_stale_restore_is_failed_lock_released_and_operator_alerted(): void
    {
        Queue::fake();

        $backup = $this->staleRestore();
        SiteOperationLock::acquire($backup->site_id, SiteOperationLock::OPERATION_RESTORE, 'backup:'.$backup->id);

        $this->artisan('backups:recover-stuck-restores')->assertSuccessful();

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
        $this->assertFalse(SiteOperationLock::isHeld($backup->site_id));
        Queue::assertPushed(NotifyRestoreFailed::class);
    }

    public function test_fresh_in_progress_restore_is_left_alone(): void
    {
        Queue::fake();

        $backup = Backup::factory()->create(['restore_status' => BackupStatus::InProgress]);

        $this->artisan('backups:recover-stuck-restores')->assertSuccessful();

        $this->assertSame(BackupStatus::InProgress, $backup->fresh()->restore_status);
        Queue::assertNotPushed(NotifyRestoreFailed::class);
    }

    public function test_lock_held_by_another_operation_is_not_released(): void
    {
        Queue::fake();

        $backup = $this->staleRestore();
        // A successor operation (e.g. a new backup) legitimately holds the lock.
        SiteOperationLock::acquire($backup->site_id, SiteOperationLock::OPERATION_BACKUP, 'backup:999');

        $this->artisan('backups:recover-stuck-restores')->assertSuccessful();

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
        $this->assertTrue(SiteOperationLock::isHeld($backup->site_id));
    }

    public function test_dry_run_changes_nothing(): void
    {
        Queue::fake();

        $backup = $this->staleRestore();
        SiteOperationLock::acquire($backup->site_id, SiteOperationLock::OPERATION_RESTORE, 'backup:'.$backup->id);

        $this->artisan('backups:recover-stuck-restores --dry-run')->assertSuccessful();

        $this->assertSame(BackupStatus::InProgress, $backup->fresh()->restore_status);
        $this->assertTrue(SiteOperationLock::isHeld($backup->site_id));
        Queue::assertNotPushed(NotifyRestoreFailed::class);
    }
}
