<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Jobs\NotifyBackupV2Failed;
use App\Backup\V2\Jobs\RunBackupSessionJob;
use App\Backup\V2\Models\BackupSession;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * A backup that dies must say so.
 *
 * The engine used to catch only CorruptBackupException. Anything else — a
 * timeout talking to the plugin, an S3 error, a quota rejection, the worker
 * being killed — escaped, leaving the session in whatever phase it died in with
 * no error recorded and no terminal state. A session frozen at `uploading` is
 * indistinguishable from one still uploading, so nothing alerted and nobody
 * knew. With tries = 1 there was no second attempt to notice either.
 */
class FailurePathTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private function sessionIn(BackupSessionState $state = BackupSessionState::Uploading): BackupSession
    {
        return $this->makeBackupSession(Site::factory()->create(), ['state' => $state]);
    }

    public function test_a_dead_job_leaves_the_session_in_a_terminal_state(): void
    {
        Queue::fake();
        $session = $this->sessionIn();

        (new RunBackupSessionJob($session->id))->failed(new RuntimeException('worker killed'));

        $session->refresh();
        $this->assertSame(BackupSessionState::Failed, $session->state);
        $this->assertTrue($session->state->isTerminal());
        $this->assertSame(BackupErrorCode::EngineError->value, $session->error_code);
        $this->assertStringContainsString('worker killed', (string) $session->error_message);
    }

    /**
     * The queue can call failed() after the engine has already recorded a more
     * specific cause. The specific one must win — "engine_error" tells an
     * operator nothing that "checksum_mismatch" does not tell them better.
     */
    public function test_a_more_specific_failure_is_not_overwritten(): void
    {
        Queue::fake();
        // corrupt is reachable only from the verify/finalize phases — integrity
        // is not something you can discover while still uploading.
        $session = $this->sessionIn(BackupSessionState::UploadVerifying);
        $session->recordError(BackupErrorCode::ChecksumMismatch, 'object sha mismatch');
        $session->transitionTo(BackupSessionState::Corrupt);

        (new RunBackupSessionJob($session->id))->failed(new RuntimeException('generic'));

        $session->refresh();
        $this->assertSame(BackupSessionState::Corrupt, $session->state);
        $this->assertSame(BackupErrorCode::ChecksumMismatch->value, $session->error_code);
    }

    public function test_a_job_that_dies_without_an_exception_still_terminates_the_session(): void
    {
        Queue::fake();
        $session = $this->sessionIn();

        (new RunBackupSessionJob($session->id))->failed(null);

        $this->assertSame(BackupSessionState::Failed, $session->fresh()->state);
    }

    public function test_a_vanished_session_is_not_an_error(): void
    {
        Queue::fake();

        (new RunBackupSessionJob(999_999))->failed(new RuntimeException('nope'));

        $this->expectNotToPerformAssertions();
    }

    // ── alerting ─────────────────────────────────────────────────────────

    public function test_reaching_failed_raises_an_alert(): void
    {
        Queue::fake();
        $session = $this->sessionIn();

        $session->transitionTo(BackupSessionState::Failed, 'engine error');

        Queue::assertPushed(
            NotifyBackupV2Failed::class,
            fn (NotifyBackupV2Failed $j) => $j->sessionId === $session->id,
        );
    }

    /**
     * Corruption is not a lesser failure. The objects that were written disagree
     * with the manifest, and no retry makes that untrue.
     */
    public function test_reaching_corrupt_raises_an_alert_too(): void
    {
        Queue::fake();
        $session = $this->sessionIn(BackupSessionState::UploadVerifying);

        $session->transitionTo(BackupSessionState::Corrupt);

        Queue::assertPushed(NotifyBackupV2Failed::class);
    }

    public function test_a_successful_backup_raises_nothing(): void
    {
        Queue::fake();
        $session = $this->sessionIn(BackupSessionState::Finalizing);

        $session->transitionTo(BackupSessionState::Completed);

        Queue::assertNotPushed(NotifyBackupV2Failed::class);
    }

    /**
     * transitionTo is idempotent on the same state, and a re-entry must not
     * alert again — a runner resuming into its own terminal state would
     * otherwise notify on every attempt.
     */
    public function test_re_entering_a_failed_state_does_not_alert_twice(): void
    {
        Queue::fake();
        $session = $this->sessionIn();

        $session->transitionTo(BackupSessionState::Failed);
        $session->transitionTo(BackupSessionState::Failed);

        Queue::assertPushed(NotifyBackupV2Failed::class, 1);
    }

    /**
     * Every route to failure goes through transitionTo, so hanging alerting off
     * that one funnel means no route can be silent. This pins the property
     * rather than any single caller.
     */
    public function test_alerting_covers_the_job_failure_route_as_well(): void
    {
        Queue::fake();
        $session = $this->sessionIn();

        (new RunBackupSessionJob($session->id))->failed(new RuntimeException('boom'));

        Queue::assertPushed(NotifyBackupV2Failed::class);
    }
}
