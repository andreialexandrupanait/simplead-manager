<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\NotifyRestoreFailed;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RestoreBackupLockTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Backup $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        $this->site = Site::factory()->create();
        $this->backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::Pending,
        ]);
    }

    public function test_unique_lock_expires_instead_of_wedging_forever(): void
    {
        $job = new RestoreBackup($this->backup);

        $this->assertSame(7200, $job->uniqueFor);
        $this->assertSame(1, $job->maxExceptions);
    }

    public function test_restore_requeues_politely_when_site_lock_is_held(): void
    {
        SiteOperationLock::acquire($this->site->id, SiteOperationLock::OPERATION_BACKUP);

        $job = (new RestoreBackup($this->backup))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(180);
        // The other operation's lock must be untouched.
        $this->assertTrue(SiteOperationLock::isHeld($this->site->id));
        $this->assertSame(BackupStatus::Pending, $this->backup->fresh()->restore_status);
    }

    public function test_restore_fails_cleanly_when_site_stays_busy_past_last_attempt(): void
    {
        SiteOperationLock::acquire($this->site->id, SiteOperationLock::OPERATION_SAFE_UPDATE);

        $job = new class($this->backup) extends RestoreBackup
        {
            public function attempts(): int
            {
                return 4;
            }
        };
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailed();
    }

    public function test_completed_restore_is_not_re_run_on_redelivery(): void
    {
        // A SIGKILLed worker is redelivered after the completed restore already
        // finished; it must skip without re-acquiring the lock or touching the site.
        $this->backup->update(['restore_status' => BackupStatus::Completed]);

        $job = (new RestoreBackup($this->backup))->withFakeQueueInteractions();
        $job->handle();

        $this->assertFalse(SiteOperationLock::isHeld($this->site->id));
        $this->assertSame(BackupStatus::Completed, $this->backup->fresh()->restore_status);
    }

    public function test_redelivered_in_progress_restore_is_not_auto_rerun(): void
    {
        // Predecessor died mid-restore (status still InProgress) and the queue
        // redelivered the job (attempts > 1); it must fail, not re-run blindly.
        $this->backup->update(['restore_status' => BackupStatus::InProgress]);

        $job = new class($this->backup) extends RestoreBackup
        {
            public function attempts(): int
            {
                return 2;
            }
        };
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailed();
        $this->assertFalse(SiteOperationLock::isHeld($this->site->id));
    }

    public function test_redelivered_failed_restore_is_not_auto_rerun(): void
    {
        // P0-06: a prior attempt died mid-restore and was marked Failed (by
        // failed() or the recovery command). ~2h later the queue redelivers the
        // job (attempts > 1). It must NOT silently re-run the whole restore
        // against a site an operator may have already hand-repaired — it fails
        // and waits for a deliberate manual re-trigger.
        $this->backup->update(['restore_status' => BackupStatus::Failed]);

        $job = new class($this->backup) extends RestoreBackup
        {
            public function attempts(): int
            {
                return 2;
            }
        };
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailed();
        $this->assertFalse(SiteOperationLock::isHeld($this->site->id));
    }

    public function test_requeued_pending_restore_still_proceeds_on_later_attempt(): void
    {
        // The guard must not over-reach: a restore that only ever waited on a
        // busy site lock stays Pending across polite requeues, so a later
        // attempt (attempts > 1) must still be allowed to acquire the lock and
        // run once the site frees up.
        SiteOperationLock::acquire($this->site->id, SiteOperationLock::OPERATION_BACKUP);
        $this->backup->update(['restore_status' => BackupStatus::Pending]);

        $job = new class($this->backup) extends RestoreBackup
        {
            public function attempts(): int
            {
                return 2;
            }
        };
        $job->withFakeQueueInteractions();
        $job->handle();

        // It did not blindly fail — it politely requeued because the site is busy.
        $job->assertReleased(180);
        $this->assertSame(BackupStatus::Pending, $this->backup->fresh()->restore_status);
    }

    public function test_failed_marks_restore_failed_releases_locks_and_alerts(): void
    {
        Queue::fake();

        $this->backup->update(['restore_status' => BackupStatus::InProgress]);
        SiteOperationLock::acquire(
            $this->site->id,
            SiteOperationLock::OPERATION_RESTORE,
            'backup:'.$this->backup->id,
        );

        (new RestoreBackup($this->backup))->failed(new \RuntimeException('worker killed'));

        $fresh = $this->backup->fresh();
        $this->assertSame(BackupStatus::Failed, $fresh->restore_status);
        $this->assertSame('worker killed', $fresh->restore_error_message);

        $this->assertFalse(SiteOperationLock::isHeld($this->site->id));

        Queue::assertPushed(NotifyRestoreFailed::class, fn ($job) => $job->site->id === $this->site->id);
        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $this->site->id,
            'severity' => 'critical',
        ]);
    }

    public function test_failed_does_not_release_a_lock_held_by_another_operation(): void
    {
        Queue::fake();

        // A different restore (other backup) holds the site lock.
        SiteOperationLock::acquire(
            $this->site->id,
            SiteOperationLock::OPERATION_RESTORE,
            'backup:999999',
        );

        (new RestoreBackup($this->backup))->failed(new \RuntimeException('boom'));

        $this->assertTrue(SiteOperationLock::isHeld($this->site->id));
    }

    public function test_failed_is_idempotent_after_handle_already_marked_failure(): void
    {
        Queue::fake();

        $this->backup->update([
            'restore_status' => BackupStatus::Failed,
            'restore_error_message' => 'original error',
        ]);

        (new RestoreBackup($this->backup))->failed(new \RuntimeException('later timeout'));

        // The original failure detail must not be overwritten.
        $this->assertSame('original error', $this->backup->fresh()->restore_error_message);
    }
}
