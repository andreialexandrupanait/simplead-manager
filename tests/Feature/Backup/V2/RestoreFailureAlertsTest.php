<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Jobs\NotifyRestoreV2Failed;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * A restore that fails has to tell somebody.
 *
 * A failed BACKUP has alerted since the engine learned to record its own
 * failures — BackupSession::transitionTo dispatches from the one funnel every
 * state change passes through. A failed RESTORE did not: RestoreSession wrote a
 * warning to the log and stopped, and nothing reads that log unless somebody
 * already suspects something.
 *
 * The asymmetry is the wrong way round. A backup that fails tonight is one you
 * take again tomorrow. A restore that fails is somebody in front of a broken
 * site — and because the engine rolls back, the site is probably fine, which
 * makes the silence more plausible and more misleading at once.
 */
class RestoreFailureAlertsTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    public function test_a_failed_restore_dispatches_an_alert(): void
    {
        Queue::fake();

        $restore = $this->makeRestoreSession(Site::factory()->create(), [
            'state' => RestoreSessionState::FileRestore,
        ]);

        $restore->transitionTo(RestoreSessionState::Failed, 'the site stopped answering');

        Queue::assertPushed(
            NotifyRestoreV2Failed::class,
            fn (NotifyRestoreV2Failed $job) => $job->restoreSessionId === $restore->id,
        );
    }

    public function test_a_restore_that_succeeds_alerts_nobody(): void
    {
        Queue::fake();

        // The last state before Completed — the machine only allows the forward
        // edge, so this is where a successful restore actually arrives from.
        $restore = $this->makeRestoreSession(Site::factory()->create(), [
            'state' => RestoreSessionState::PostRestoreValidation,
        ]);

        $restore->transitionTo(RestoreSessionState::Completed);

        Queue::assertNotPushed(NotifyRestoreV2Failed::class);
    }

    /**
     * Transitions are re-entered by recovery sweeps and by the runner's own
     * finalisation. Alerting on a no-op transition would page somebody twice for
     * one failure, which is how people learn to ignore the alert.
     */
    public function test_a_repeated_failed_transition_does_not_alert_twice(): void
    {
        Queue::fake();

        $restore = $this->makeRestoreSession(Site::factory()->create(), [
            'state' => RestoreSessionState::FileRestore,
        ]);

        $restore->transitionTo(RestoreSessionState::Failed, 'first');
        $restore->transitionTo(RestoreSessionState::Failed, 'again');

        Queue::assertPushed(NotifyRestoreV2Failed::class, 1);
    }
}
