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
            sleeper: static fn (int $s) => null,
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
     */
    public function __construct(
        private readonly bool $async,
        private readonly array $statuses,
    ) {}

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

    public function restoreApplyAsync(string $token): array
    {
        return ['ok' => true, 'async' => $this->async, 'token' => $token];
    }

    public function restoreCommit(string $token): array
    {
        $this->commits++;

        return ['ok' => true];
    }

    public function restoreRollback(string $token): array
    {
        $this->rollbacks++;

        return ['ok' => true, 'rolled_back' => true];
    }

    public function restoreStatus(string $token): array
    {
        $next = $this->statuses[$this->poll] ?? end($this->statuses);
        $this->poll++;

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_array($next) ? $next : ['state' => 'unknown'];
    }
}
