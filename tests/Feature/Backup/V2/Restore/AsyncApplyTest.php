<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Enums\RestoreSessionState as R;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Plugin\PluginClientException;
use App\Backup\V2\Restore\RestoreClient;
use App\Backup\V2\Restore\RestoreRunner;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * The defect this exists to prevent, in the words of the day it happened:
 *
 *   "restore/apply is one synchronous HTTP call with a 120 second client timeout. On the real site
 *    it timed out, the plugin finished the swap anyway, and the manager recorded failed / rolled
 *    back to pre-apply while the site was completely and correctly restored. 462 MB of the old site
 *    was left on the customer's disk because commit was never reached."
 *
 * A restore that reports the opposite of what happened is worse than one that fails cleanly: it
 * sends someone to fix a site that is fine, or to trust a rollback that never ran. So the two
 * properties pinned here are that a slow apply is waited for rather than abandoned, and that when
 * contact IS lost the manager asks the site what happened before undoing anything.
 */
class AsyncApplyTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    public function test_a_slow_apply_is_waited_for_rather_than_abandoned(): void
    {
        // Three polls of "still working" before it finishes — the shape of a real site, where the
        // old code would have given up at the first timeout.
        $client = new FakeRestoreClient(
            async: true,
            statuses: [
                ['state' => 'applying', 'phase' => 'database'],
                ['state' => 'applying', 'phase' => 'files'],
                ['state' => 'applying', 'phase' => 'files'],
                ['state' => 'applied', 'db' => true, 'files' => true],
            ],
        );

        $restore = $this->runRestore($client);

        $this->assertSame(
            R::Completed,
            $restore->state,
            'a slow restore is still a successful one; got '.$restore->state->value.' — '.(string) $restore->error_message,
        );
        $this->assertTrue((bool) $restore->checkpoint['apply']['applied']);
        $this->assertTrue((bool) $restore->checkpoint['apply']['async'], 'it took the detached path');
        $this->assertSame(0, $client->rollbacks, 'nothing was rolled back');
    }

    public function test_losing_contact_after_the_site_finished_does_not_roll_back_a_good_restore(): void
    {
        // The exact production sequence: the apply is dispatched, contact is lost while it runs —
        // and by the time we ask, the site has finished.
        $client = new FakeRestoreClient(
            async: true,
            statuses: [
                ['state' => 'applying', 'phase' => 'files'],
                new PluginClientException('cURL error 28: Operation timed out'),
                new PluginClientException('cURL error 28: Operation timed out'),
                new PluginClientException('cURL error 28: Operation timed out'),
                new PluginClientException('cURL error 28: Operation timed out'),
                new PluginClientException('cURL error 28: Operation timed out'),
                // handleMutatingFailure() asks once more before deciding anything.
                ['state' => 'applied', 'db' => true, 'files' => true],
            ],
        );

        $restore = $this->runRestore($client);

        $this->assertSame(
            0,
            $client->rollbacks,
            'the site said it had finished — undoing it would destroy a restore that worked',
        );
        $this->assertSame(1, $client->commits, 'and the interrupted transaction is completed, so no old copy is left behind');
        $this->assertTrue((bool) ($restore->checkpoint['recovered_from_transport_failure'] ?? false));
        $this->assertNotSame(R::Failed, $restore->state);
    }

    public function test_a_genuine_failure_is_still_rolled_back(): void
    {
        // The safe direction when the site says it failed is unchanged.
        $client = new FakeRestoreClient(
            async: true,
            statuses: [
                ['state' => 'applying', 'phase' => 'database'],
                ['state' => 'failed', 'error' => 'could not import wp_posts'],
                ['state' => 'failed', 'error' => 'could not import wp_posts'],
            ],
        );

        $restore = $this->runRestore($client);

        $this->assertSame(1, $client->rollbacks, 'a real failure must still put the site back');
        $this->assertSame(R::Failed, $restore->state);
    }

    /**
     * A worker killed mid-restore and redelivered. The apply it started has since finished, so the
     * job must pick up the verdict rather than start a second one — which used to clear the first
     * one's rollback data on the way in.
     */
    public function test_a_redelivered_job_picks_up_an_apply_that_already_finished(): void
    {
        $client = new FakeRestoreClient(
            async: true,
            statuses: [['state' => 'applied', 'db' => true, 'files' => true]],
            kickExtra: ['already_applied' => true],
        );

        $restore = $this->runRestore($client);

        $this->assertSame(R::Completed, $restore->state);
        $this->assertSame(0, $client->syncApplies, 'it must not re-run the apply');
        $this->assertSame(0, $client->rollbacks);
    }

    /**
     * A rollback the site refused must not be recorded as a rollback that happened. That is how
     * somebody comes to believe a half-swapped site is safe, walks away, and finds out later.
     */
    public function test_a_refused_rollback_is_not_reported_as_a_rollback(): void
    {
        $client = new FakeRestoreClient(
            async: true,
            statuses: [['state' => 'failed', 'error' => 'could not import wp_posts']],
            rollbackThrows: new PluginClientException('restore/rollback returned HTTP 500'),
        );

        $restore = $this->runRestore($client);

        $this->assertSame(R::Failed, $restore->state);
        $this->assertFalse(
            (bool) ($restore->checkpoint['rolled_back'] ?? true),
            'the rollback did not happen, so nothing may claim it did',
        );
        $this->assertStringContainsString(
            'ROLLBACK FAILED',
            (string) $restore->stage,
            'and the operator has to be able to see that from the session alone',
        );
    }

    /**
     * Seen for real on the first async attempt: the host accepted the loopback and then answered it
     * with a 500, so the restore sat claimed-but-never-started while the manager polled it happily
     * towards a fifty-minute deadline. Nothing had been touched, so the answer is to stop waiting
     * and do it in-band — not to refuse, and not to keep watching.
     */
    public function test_an_apply_that_is_accepted_but_never_starts_falls_back_to_synchronous(): void
    {
        $client = new FakeRestoreClient(
            async: true,
            statuses: [['state' => 'applying', 'phase' => 'queued']], // never moves off queued
        );

        $restore = $this->runRestore($client);

        $this->assertSame(R::Completed, $restore->state, 'the restore still has to happen; got '.$restore->state->value.' — '.(string) $restore->error_message);
        $this->assertSame(1, $client->syncApplies, 'it was done in-band once waiting proved pointless');
        $this->assertSame(0, $client->rollbacks, 'nothing was applied, so there was nothing to undo');
        $this->assertFalse((bool) $restore->checkpoint['apply']['async']);
    }

    /**
     * The second thing a real host taught us: apply() puts the site into maintenance for the swap,
     * and WordPress serves 503 for everything while that file exists — this endpoint included. So
     * for most of a real apply the only answer available IS a 503, and reading it as lost contact
     * meant abandoning a restore that was working, then failing to roll back for the same reason.
     *
     * A 503 during an apply is not a failure. It is the site saying it is busy doing the thing.
     */
    public function test_maintenance_mode_is_read_as_progress_not_as_lost_contact(): void
    {
        $maintenance = new PluginClientException('simplead-backup restore/status returned HTTP 503', 503);

        $client = new FakeRestoreClient(
            async: true,
            statuses: [
                ['state' => 'applying', 'phase' => 'database'],
                // Longer than MAX_POLL_FAILURES: under the old rule this alone ended the restore.
                $maintenance, $maintenance, $maintenance, $maintenance,
                $maintenance, $maintenance, $maintenance, $maintenance,
                ['state' => 'applied', 'db' => true, 'files' => true],
            ],
        );

        $restore = $this->runRestore($client);

        $this->assertSame(
            R::Completed,
            $restore->state,
            'a site in maintenance is a site mid-restore; got '.$restore->state->value.' — '.(string) $restore->error_message,
        );
        $this->assertSame(0, $client->rollbacks, 'and nothing was undone on account of it');
    }

    public function test_a_host_that_cannot_detach_is_applied_synchronously(): void
    {
        // A locked-down host with no loopback and no cron. Refusing to restore it would be worse
        // than taking the old path, which is still correct for a site small enough to fit.
        $client = new FakeRestoreClient(async: false, statuses: []);

        $restore = $this->runRestore($client);

        $this->assertSame(R::Completed, $restore->state);
        $this->assertSame(1, $client->syncApplies, 'it fell back rather than refusing');
        $this->assertFalse((bool) $restore->checkpoint['apply']['async']);
    }

    // ── harness ──────────────────────────────────────────────────────────

    private function runRestore(FakeRestoreClient $client): RestoreSession
    {
        $site = Site::factory()->create();
        $backup = $this->makeBackupSession($site, ['type' => 'full', 'state' => \App\Backup\V2\Enums\BackupSessionState::Completed]);

        $restore = RestoreSession::create([
            'site_id' => $site->id,
            'backup_session_id' => $backup->id,
            'mode' => 'safe_merge',
            'scope' => ['database' => false, 'files' => true],
            // Started past validation and download on purpose. Those phases reach for real storage
            // and are covered by RestoreRunnerE2ETest against the lab; what is under test here is
            // what the runner DECIDES around the apply, and mixing the two would mean standing up
            // an object store to assert a control-flow question.
            'state' => R::Verifying,
            'checkpoint' => [],
            'idempotency_key' => 'async-'.uniqid('', true),
        ]);

        $layoutFor = static fn (BackupSession $b): ObjectLayout => new ObjectLayout(1, (int) $b->site_id, (int) $b->id, 'test/{client_id}/{site_id}/{backup_id}');

        (new RestoreRunner(
            session: $restore,
            client: $client,
            s3: $this->s3Stub(),
            bucket: 'irrelevant',
            resolver: new ChainResolver,
            reader: new StaticManifestReader,
            layoutFor: $layoutFor,
            preRestoreBackup: null,
            healthCheck: fn (): bool => true,
            // Time travels instead of passing, so a poll loop that waits minutes costs the suite
            // nothing while the deadlines it is built around still expire.
            sleeper: static fn (int $seconds) => \Illuminate\Support\Carbon::setTestNow(now()->addSeconds($seconds)),
        ))->run();

        return $restore->fresh();
    }

    private function s3Stub(): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'x', 'secret' => 'y'],
        ]);
    }
}

/**
 * A manifest with no file objects at all: the restore has nothing to download, so these tests
 * exercise the apply/poll/rollback decisions without needing storage.
 */
final class StaticManifestReader implements ManifestReader
{
    public function read(BackupSession $session): array
    {
        return [
            'format_version' => 'simplead-backup/2',
            'objects' => [],
            'files' => ['included' => [], 'tombstones' => []],
        ];
    }
}

/**
 * Scripted host. Each entry in $statuses is either the JSON a status poll returns, or an exception
 * to throw for that poll — which is how a timed-out site is reproduced without one.
 */
final class FakeRestoreClient implements RestoreClient
{
    public int $rollbacks = 0;

    public int $commits = 0;

    public int $syncApplies = 0;

    private int $poll = 0;

    /**
     * @param  list<array<string, mixed>|\Throwable>  $statuses
     * @param  array<string, mixed>  $kickExtra
     */
    public function __construct(
        private readonly bool $async,
        private readonly array $statuses,
        private readonly array $kickExtra = [],
        private readonly ?\Throwable $rollbackThrows = null,
    ) {}

    /** A host with room to spare: these tests are about the apply, not the preflight. */
    public function capabilities(): array
    {
        return [
            'disk' => [
                'temp_dir' => '/tmp',
                'free_bytes' => 500 * 1024 * 1024 * 1024,
                'site_dir' => '/var/www/html',
                'site_free_bytes' => 500 * 1024 * 1024 * 1024,
                'same_filesystem' => false,
            ],
        ];
    }

    public function restorePrepare(string $token, array $opts): array
    {
        return ['ok' => true];
    }

    public function restoreStageChunk(string $token, string $kind, int $seq, string $localPath): array
    {
        return ['ok' => true];
    }

    public function restoreApply(string $token): array
    {
        $this->syncApplies++;

        return ['ok' => true, 'applied' => true, 'db' => null, 'files' => true];
    }

    public function restoreSupportsAsync(): bool
    {
        return $this->async;
    }

    public function restoreApplyAsync(string $token): array
    {
        return array_merge(['ok' => true, 'async' => $this->async, 'token' => $token], $this->kickExtra);
    }

    public function restoreCommit(string $token): array
    {
        $this->commits++;

        return ['ok' => true];
    }

    public function restoreRollback(string $token): array
    {
        $this->rollbacks++;

        if ($this->rollbackThrows !== null) {
            throw $this->rollbackThrows;
        }

        return ['ok' => true, 'rolled_back' => true];
    }

    public function restoreStatus(string $token): array
    {
        // Past the end of the script the last entry repeats — a host that keeps saying the same
        // thing. end() would move the internal pointer of a readonly property, which PHP refuses.
        $next = $this->statuses[$this->poll]
            ?? ($this->statuses === [] ? null : $this->statuses[array_key_last($this->statuses)]);
        $this->poll++;

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_array($next) ? $next : ['state' => 'unknown'];
    }
}
