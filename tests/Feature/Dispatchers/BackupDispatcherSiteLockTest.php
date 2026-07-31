<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\BackupDispatcher;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Stuck recovery has to hand back the site lock — but only its own.
 *
 * SiteOperationLock serialises backup / incremental / restore / safe update
 * across job classes, with a two-hour TTL. Recovery released the jobs' unique
 * locks and nothing else, so a worker killed mid-backup left the site locked for
 * the full two hours: recovery re-dispatched, the fresh job could not acquire,
 * and the site took no backup, restore or update until the lock aged out. The
 * one mechanism meant to unstick a site was the only thing that could not.
 *
 * The other half matters more. A blind force-release would take the lock from a
 * restore that is running perfectly well, and a backup would start on top of it —
 * turning a stuck-backup cleanup into concurrent writes on a client's site.
 */
class BackupDispatcherSiteLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
    }

    private function recover(): void
    {
        $dispatcher = new BackupDispatcher;
        $method = new \ReflectionMethod($dispatcher, 'recoverStuckBackups');
        $method->setAccessible(true);
        $method->invoke($dispatcher);
    }

    /** A backup old enough that recovery treats it as dead. */
    private function stuckBackup(Site $site, int $autoRetryCount = 0): Backup
    {
        return Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::InProgress,
            'auto_retry_count' => $autoRetryCount,
            'started_at' => now()->subHours(3),
            'updated_at' => now()->subHours(2),
        ]);
    }

    public function test_recovery_releases_the_lock_a_dead_backup_job_was_holding(): void
    {
        $site = Site::factory()->create();
        $backup = $this->stuckBackup($site);

        // What CreateBackup itself writes: BackupJobTrait::acquireSiteLock uses
        // static::class.':'.$backupId as the ref.
        SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_BACKUP,
            CreateBackup::class.':'.$backup->id,
        );
        $this->assertTrue(SiteOperationLock::isHeld($site->id));

        $this->recover();

        $this->assertFalse(
            SiteOperationLock::isHeld($site->id),
            'a dead backup job must not keep the site locked for its full TTL',
        );
    }

    public function test_an_incremental_jobs_lock_is_released_too(): void
    {
        $site = Site::factory()->create();
        $backup = $this->stuckBackup($site);

        SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_INCREMENTAL_BACKUP,
            CreateIncrementalBackup::class.':'.$backup->id,
        );

        $this->recover();

        $this->assertFalse(SiteOperationLock::isHeld($site->id));
    }

    /**
     * The important one. A restore holding the lock is not a leak — it is a
     * restore. Releasing it would let the re-dispatched backup run concurrently
     * with a restore that is mid-flight on the client's site.
     */
    public function test_a_running_restores_lock_is_left_alone(): void
    {
        $site = Site::factory()->create();
        $this->stuckBackup($site);

        SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_RESTORE,
            RestoreBackup::class.':7',
        );

        $this->recover();

        $this->assertTrue(
            SiteOperationLock::isHeld($site->id),
            'recovery must never take the lock from a restore',
        );
        $this->assertSame(
            SiteOperationLock::OPERATION_RESTORE,
            SiteOperationLock::current($site->id)['operation'] ?? null,
        );
    }

    public function test_a_safe_updates_lock_is_left_alone(): void
    {
        $site = Site::factory()->create();
        $this->stuckBackup($site);

        SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_SAFE_UPDATE,
            \App\Jobs\RunSafeUpdate::class.':3',
        );

        $this->recover();

        $this->assertTrue(SiteOperationLock::isHeld($site->id));
    }

    /**
     * Exhausted retries take the same path through markBackupFailed(), which had
     * the identical omission.
     */
    public function test_the_lock_is_released_when_retries_are_exhausted(): void
    {
        $site = Site::factory()->create();
        // 3 auto-retries is past the limit of 2, so recovery fails it outright
        // instead of retrying.
        $backup = $this->stuckBackup($site, autoRetryCount: 3);

        SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_BACKUP,
            CreateBackup::class.':'.$backup->id,
        );

        $this->recover();

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->status);
        $this->assertFalse(SiteOperationLock::isHeld($site->id));
    }

    public function test_no_lock_at_all_is_not_an_error(): void
    {
        $site = Site::factory()->create();
        $this->stuckBackup($site);

        $this->recover();

        $this->assertFalse(SiteOperationLock::isHeld($site->id));
    }
}
