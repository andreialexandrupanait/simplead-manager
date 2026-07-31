<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Jobs\RunBackupSessionJob;
use App\Backup\V2\Models\BackupSession;
use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * Only one thing touches a client's site at a time.
 *
 * V1's backup, incremental, restore and safe-update jobs all take
 * SiteOperationLock. The new engine took nothing, so the two could run on the
 * same WordPress install at once: one building an archive on the host while the
 * other chunked the same filesystem and dumped the same database. Neither would
 * notice, and the site would carry the cost.
 *
 * The other half is a lock that is never handed back. A SIGKILLed worker runs
 * neither the runner's error path nor failed(), so without an attendant the site
 * stays locked for the full two-hour TTL — taking no backup, no restore and no
 * update from either engine.
 */
class MutualExclusionTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Config::set('backup_v2.enabled', true);
    }

    private function allowedSession(array $attrs = []): BackupSession
    {
        $site = Site::factory()->create();
        Config::set('backup_v2.site_ids', [(string) $site->id]);

        return $this->makeBackupSession($site, $attrs + ['state' => S::Requested]);
    }

    public function test_a_session_parks_rather_than_running_over_a_restore(): void
    {
        $session = $this->allowedSession();
        SiteOperationLock::acquire(
            (int) $session->site_id,
            SiteOperationLock::OPERATION_RESTORE,
            RestoreBackup::class.':9',
        );

        (new RunBackupSessionJob($session->id))->handle();

        $session->refresh();
        $this->assertSame(S::RetryWait, $session->state, 'a busy site defers the backup, it does not fail it');
        $this->assertNotNull($session->next_retry_at);
        // The restore keeps its lock — that is the entire point.
        $this->assertSame(
            SiteOperationLock::OPERATION_RESTORE,
            SiteOperationLock::current((int) $session->site_id)['operation'],
        );
    }

    /**
     * The unique key used to name the session, so two sessions for one site were
     * two different keys and nothing stopped both from running.
     */
    public function test_the_unique_key_names_the_site_not_the_session(): void
    {
        $site = Site::factory()->create();
        $a = $this->makeBackupSession($site);
        $b = $this->makeBackupSession($site);

        $this->assertSame(
            (new RunBackupSessionJob($a->id))->uniqueId(),
            (new RunBackupSessionJob($b->id))->uniqueId(),
        );
        $this->assertStringContainsString((string) $site->id, (new RunBackupSessionJob($a->id))->uniqueId());
    }

    public function test_a_dead_job_hands_the_lock_back(): void
    {
        $session = $this->allowedSession(['state' => S::Uploading]);
        SiteOperationLock::acquire(
            (int) $session->site_id,
            SiteOperationLock::OPERATION_BACKUP,
            RunBackupSessionJob::class.':'.$session->id,
        );

        (new RunBackupSessionJob($session->id))->failed(new RuntimeException('worker killed'));

        $this->assertFalse(SiteOperationLock::isHeld((int) $session->site_id));
    }

    public function test_a_dead_job_does_not_take_someone_elses_lock(): void
    {
        $session = $this->allowedSession(['state' => S::Uploading]);
        SiteOperationLock::acquire(
            (int) $session->site_id,
            SiteOperationLock::OPERATION_SAFE_UPDATE,
            \App\Jobs\RunSafeUpdate::class.':4',
        );

        (new RunBackupSessionJob($session->id))->failed(new RuntimeException('worker killed'));

        $this->assertTrue(SiteOperationLock::isHeld((int) $session->site_id));
    }

    // ── the attendant ────────────────────────────────────────────────────

    public function test_a_session_with_a_dead_heartbeat_is_failed_and_its_lock_released(): void
    {
        $session = $this->allowedSession(['state' => S::Uploading]);
        $session->heartbeat_at = now()->subHours(3);
        $session->save();
        SiteOperationLock::acquire(
            (int) $session->site_id,
            SiteOperationLock::OPERATION_BACKUP,
            RunBackupSessionJob::class.':'.$session->id,
        );

        $this->artisan('backups:recover-stuck-sessions')->assertSuccessful();

        $this->assertSame(S::Failed, $session->fresh()->state);
        $this->assertFalse(SiteOperationLock::isHeld((int) $session->site_id));
    }

    /**
     * A session that never got a heartbeat died before its first phase. Without
     * started_at as the fallback it would sit in `requested` forever, invisible
     * to the sweep that exists to find it.
     */
    public function test_a_session_that_never_started_is_caught_too(): void
    {
        $session = $this->allowedSession(['state' => S::CapabilityCheck]);
        $session->heartbeat_at = null;
        $session->started_at = now()->subHours(3);
        $session->save();

        $this->artisan('backups:recover-stuck-sessions')->assertSuccessful();

        $this->assertSame(S::Failed, $session->fresh()->state);
    }

    public function test_a_working_session_is_left_alone(): void
    {
        $session = $this->allowedSession(['state' => S::Uploading]);
        $session->heartbeat_at = now()->subMinutes(2);
        $session->save();

        $this->artisan('backups:recover-stuck-sessions')->assertSuccessful();

        $this->assertSame(S::Uploading, $session->fresh()->state);
    }

    /**
     * retry_wait is only an honest state if something comes back for it.
     * Otherwise it is the same silent stall as a session frozen at `uploading`,
     * with a nicer name.
     */
    public function test_a_parked_session_is_re_dispatched_once_it_is_due(): void
    {
        $session = $this->allowedSession(['state' => S::RetryWait]);
        $session->next_retry_at = now()->subMinute();
        $session->heartbeat_at = now();
        $session->save();

        $this->artisan('backups:recover-stuck-sessions')->assertSuccessful();

        Queue::assertPushed(
            RunBackupSessionJob::class,
            fn (RunBackupSessionJob $j) => $j->backupSessionId === $session->id,
        );
    }

    public function test_a_parked_session_that_is_not_due_yet_waits(): void
    {
        $session = $this->allowedSession(['state' => S::RetryWait]);
        $session->next_retry_at = now()->addMinutes(10);
        $session->save();

        $this->artisan('backups:recover-stuck-sessions')->assertSuccessful();

        Queue::assertNotPushed(RunBackupSessionJob::class);
    }

    /**
     * The V1 sweep must never be the thing that recovers a V2 session: it
     * recovers by dispatching CreateBackup, which would put the old engine on
     * top of a live one.
     */
    public function test_the_attendant_never_dispatches_the_old_engine(): void
    {
        $session = $this->allowedSession(['state' => S::Uploading]);
        $session->heartbeat_at = now()->subHours(3);
        $session->save();

        $this->artisan('backups:recover-stuck-sessions')->assertSuccessful();

        Queue::assertNotPushed(CreateBackup::class);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $session = $this->allowedSession(['state' => S::Uploading]);
        $session->heartbeat_at = now()->subHours(3);
        $session->save();

        $this->artisan('backups:recover-stuck-sessions', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(S::Uploading, $session->fresh()->state);
    }
}
