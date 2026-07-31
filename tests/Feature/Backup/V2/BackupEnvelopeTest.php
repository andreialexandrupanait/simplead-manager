<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Jobs\NotifyBackupV2Failed;
use App\Backup\V2\Orchestration\BackupEnvelope;
use App\Backup\V2\Orchestration\SessionActions;
use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * A V2 session has to be visible to the rest of the application.
 *
 * The engine was built beside the app, so nothing users look at could see it:
 * the site history, the overview, the dashboard, the health score, the alerts,
 * the stale-backup sweep and chain retention all read `backups`, and the engine
 * never wrote one. A site could be backed up perfectly every night and still
 * report "no backup in 36 hours" the next morning.
 *
 * Rather than teach five subsystems about a second table, every session now
 * carries a `backups` row. What matters is that the row moves through states
 * rather than appearing in its final one — BackupObserver fires on the
 * transition, so a row inserted straight as `completed` would be ignored and
 * sites.last_backup_at would never move.
 */
class BackupEnvelopeTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
    }

    private function site(): Site
    {
        $site = Site::factory()->create();
        $destination = StorageDestination::factory()->create([
            'type' => 's3',
            'is_active' => true,
            'is_default' => true,
        ]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
        ]);

        return $site;
    }

    // ── opening ──────────────────────────────────────────────────────────

    public function test_starting_a_session_opens_a_pending_backup_row(): void
    {
        $site = $this->site();

        $session = app(SessionActions::class)->startBackup($site, 'full');

        $this->assertNotNull($session->backup_id);
        $backup = Backup::findOrFail($session->backup_id);
        $this->assertSame(BackupEngine::V2, $backup->engine);
        $this->assertSame(BackupStatus::Pending, $backup->status);
        $this->assertSame($site->id, $backup->site_id);
        $this->assertSame('full', $backup->type);
    }

    /**
     * A session that dies in its first phase must still leave a row, or the site
     * goes on reporting its last good backup and nothing alerts.
     */
    public function test_the_row_exists_before_any_work_happens(): void
    {
        $site = $this->site();

        $session = app(SessionActions::class)->startBackup($site, 'full');

        $this->assertSame(S::Requested, $session->state);
        $this->assertDatabaseHas('backups', ['id' => $session->backup_id, 'status' => 'pending']);
    }

    /**
     * file_path is the object prefix, and SessionLayoutResolver is the only
     * thing allowed to decide it. Computing it again at open() would reintroduce
     * exactly the drift that freezing the prefix was meant to end.
     */
    public function test_the_prefix_is_copied_from_the_session_not_recomputed(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');

        $this->assertNull(Backup::findOrFail($session->backup_id)->file_path);

        $session->object_prefix = 'clients/9/sites/'.$site->id.'/backups/'.$session->backup_id;
        $session->save();
        $session->transitionTo(S::CapabilityCheck);

        $this->assertSame(
            'clients/9/sites/'.$site->id.'/backups/'.$session->backup_id,
            Backup::findOrFail($session->backup_id)->file_path,
        );
    }

    // ── progression ──────────────────────────────────────────────────────

    public function test_the_row_follows_the_session_through_its_phases(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');

        $session->transitionTo(S::CapabilityCheck);
        $this->assertSame(BackupStatus::InProgress, Backup::findOrFail($session->backup_id)->status);

        $session->transitionTo(S::Inventory);
        $this->assertSame('inventory', Backup::findOrFail($session->backup_id)->stage);

        $session->transitionTo(S::DatabaseExport);
        $this->assertGreaterThan(12, Backup::findOrFail($session->backup_id)->progress_percent);
    }

    /**
     * The transition the whole envelope exists for. The observer keys off it to
     * move sites.last_backup_at — which is what the stale-backup sweep and the
     * dashboard read.
     */
    public function test_completion_moves_the_sites_last_backup_at(): void
    {
        $site = $this->site();
        $site->update(['last_backup_at' => null, 'backup_ok' => false]);
        $session = app(SessionActions::class)->startBackup($site, 'full');

        foreach ([S::CapabilityCheck, S::Inventory, S::DatabaseExport, S::FileDiff, S::Chunking,
            S::UploadInitializing, S::Uploading, S::UploadVerifying, S::Finalizing, S::Completed] as $state) {
            $session->transitionTo($state);
        }

        $backup = Backup::findOrFail($session->backup_id);
        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertSame(100, $backup->progress_percent);
        $this->assertNotNull($site->fresh()->last_backup_at);
        $this->assertTrue($site->fresh()->backup_ok);
        $this->assertSame('completed', $site->fresh()->backupConfig->last_backup_status);
    }

    public function test_the_recorded_size_includes_the_sidecars(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        $session->confirmed_objects = [
            'files:0' => ['key' => 'a', 'size' => 1000, 'sha256' => 'x'],
            'db:0' => ['key' => 'b', 'size' => 500, 'sha256' => 'y'],
        ];
        $session->checkpoint = ['sidecar_bytes' => 340];
        $session->save();

        $session->transitionTo(S::CapabilityCheck);
        $session->state = S::Finalizing;
        $session->save();
        $session->transitionTo(S::Completed);

        // Retention gives used_bytes back by exactly this number, so anything
        // omitted is never reclaimed.
        $this->assertSame(1840, Backup::findOrFail($session->backup_id)->file_size);
    }

    // ── failure ──────────────────────────────────────────────────────────

    public function test_failure_marks_the_row_and_the_site(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        $session->transitionTo(S::CapabilityCheck);
        $session->error_message = 'host refused the connection';
        $session->save();

        $session->transitionTo(S::Failed);

        $backup = Backup::findOrFail($session->backup_id);
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('host refused', (string) $backup->error_message);
        $this->assertFalse($site->fresh()->backup_ok);
        $this->assertSame('failed', $site->fresh()->backupConfig->last_backup_status);
    }

    /**
     * `corrupt` has no equivalent in BackupStatus, which the database constrains
     * to five values. Widening a CHECK constraint on the live table for one
     * distinction is not worth it; it survives in the text and on the session.
     */
    public function test_corrupt_lands_as_failed_but_says_so(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        $session->state = S::UploadVerifying;
        $session->error_message = 'object sha mismatch';
        $session->save();

        $session->transitionTo(S::Corrupt);

        $backup = Backup::findOrFail($session->backup_id);
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('[corrupt]', (string) $backup->error_message);
        $this->assertSame(S::Corrupt, $session->fresh()->state);
    }

    /**
     * A parked session is not a failed one. Leaving the row non-terminal keeps
     * the observer quiet about a backup that is about to resume from checkpoint.
     */
    public function test_retry_wait_keeps_the_row_pending_and_silent(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        $session->state = S::Uploading;
        $session->save();

        $session->transitionTo(S::RetryWait);

        $backup = Backup::findOrFail($session->backup_id);
        $this->assertSame(BackupStatus::Pending, $backup->status);
        $this->assertSame('retrying', $backup->stage);
        Queue::assertNotPushed(NotifyBackupFailed::class);
        Queue::assertNotPushed(NotifyBackupV2Failed::class);
    }

    // ── alerting ─────────────────────────────────────────────────────────

    /**
     * Both alerts use the same `backup_failed` event, so leaving the V1 observer
     * in place would not produce two messages — NotificationService dedups on a
     * five-minute window — it would produce a race over which one the operator
     * reads. The V2 alert wins because it carries the phase, a typed error code
     * and the session id.
     */
    public function test_a_v2_failure_raises_only_the_engines_own_alert(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        $session->transitionTo(S::CapabilityCheck);

        $session->transitionTo(S::Failed);

        Queue::assertPushed(NotifyBackupV2Failed::class);
        Queue::assertNotPushed(NotifyBackupFailed::class);
    }

    public function test_a_v1_failure_still_raises_the_v1_alert(): void
    {
        $site = $this->site();
        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'engine' => BackupEngine::V1,
            'status' => BackupStatus::InProgress,
        ]);

        $backup->update(['status' => BackupStatus::Failed, 'error_message' => 'boom']);

        Queue::assertPushed(NotifyBackupFailed::class);
    }

    // ── verification write-through ───────────────────────────────────────

    /**
     * Verification runs after the session reaches `completed`, so sync() has
     * nothing to copy at the time. Without the write-through every V2 backup
     * reads as "not verified in the last 14 days" and caps the site at 80.
     */
    public function test_a_passed_verification_is_written_to_the_row(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');

        app(BackupEnvelope::class)->recordVerification($session, true, '3 objects present');

        $backup = Backup::findOrFail($session->backup_id);
        $this->assertNotNull($backup->verified_at);
        $this->assertSame('passed', $backup->verification_status);
    }

    public function test_a_failed_verification_withdraws_the_stamp(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        app(BackupEnvelope::class)->recordVerification($session, true, 'ok');

        app(BackupEnvelope::class)->recordVerification($session, false, 'object missing');

        $backup = Backup::findOrFail($session->backup_id);
        $this->assertNull($backup->verified_at);
        $this->assertSame('failed', $backup->verification_status);
    }

    // ── sessions without a row ───────────────────────────────────────────

    /**
     * Every V2 fixture in the suite builds a session directly, with no row. The
     * envelope has to be a no-op for those, or the whole existing test surface
     * would need rewriting to prove something it is not about.
     */
    public function test_a_session_without_a_row_transitions_normally(): void
    {
        $session = $this->makeBackupSession(Site::factory()->create(), ['state' => S::Uploading]);

        $session->transitionTo(S::UploadVerifying);

        $this->assertSame(S::UploadVerifying, $session->fresh()->state);
        $this->assertNull($session->backup_id);
    }

    public function test_a_row_that_was_deleted_underneath_does_not_break_the_session(): void
    {
        $site = $this->site();
        $session = app(SessionActions::class)->startBackup($site, 'full');
        Backup::query()->whereKey($session->backup_id)->delete();

        $session->transitionTo(S::CapabilityCheck);

        $this->assertSame(S::CapabilityCheck, $session->fresh()->state);
    }
}
