<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use App\Backup\V2\Chain\BrokenChainException;
use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Crypto\BackupKeyring;
use App\Backup\V2\Crypto\ObjectCipher;
use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\RestoreMode;
use App\Backup\V2\Enums\RestoreSessionState as S;
use App\Backup\V2\Exceptions\PreflightFailed;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Plugin\PluginClientException;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\WorkDir;
use App\Backup\V2\Support\BackupLogger;
use Aws\S3\S3Client;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Drives a single RestoreSession through the V2 restore FSM to a terminal state — the piece that
 * ties chain resolution, the pre-restore safety backup, the S3→plugin staged transfer, the atomic
 * swap and post-restore validation (with guaranteed rollback) together.
 *
 * FSM path (every transition goes through RestoreSession::transitionTo — no shortcuts):
 *   requested → validating_backup → pre_restore_backup → downloading → decrypting → verifying →
 *   maintenance → database_restore → file_restore → cleanup → post_restore_validation → completed
 *   (post_restore_validation → rollback → failed on a failed health-check)
 *
 * State ↔ work:
 *   validating_backup     resolve target + chain (BrokenChain → refuse), assert _COMPLETE, build plan
 *   pre_restore_backup    take a safety backup of the live site (MANDATORY for MIRROR)
 *   downloading           pull each planned object from S3 and PUSH it to plugin staging (resumable)
 *   decrypting            (placeholder — objects are stored plaintext in the lab)
 *   verifying             assert every planned chunk staged with a matching sha256
 *   maintenance→db→file   restore/apply: DB import+RENAME swap + journaled file swap (atomic window)
 *   cleanup               free manager-side temp (plugin rollback data is KEPT until validation)
 *   post_restore_validation  health-check → commit (drop old/trash) OR rollback (reverse) → failed
 *
 * Invariant: the site is NEVER left broken. apply() self-rolls-back a mid-swap failure; a failed
 * validation triggers restore/rollback (+ the pre-restore backup as a backstop).
 */
final class RestoreRunner
{
    /**
     * How long a detached apply may run before we stop waiting.
     *
     * Generous on purpose. The old ceiling was the HTTP client's 120 seconds, which a real site
     * exceeds routinely — and being wrong in that direction cost a correct restore its verdict.
     * Giving up too early is the expensive mistake here; waiting is only slow.
     */
    private const APPLY_MAX_WAIT_SECONDS = 3000;

    private const POLL_INTERVAL_SECONDS = 5;

    /**
     * How long the host may sit on a dispatched-but-not-started apply before we stop believing it.
     *
     * The plugin claims the work the instant it accepts the kick, and the detached request should
     * pick it up in seconds. If it never does — a loopback that was accepted and then failed, a
     * cron that never fires — the claim alone would otherwise keep us polling until the full apply
     * deadline, which is fifty minutes of watching a restore that never began.
     */
    private const QUEUED_GRACE_SECONDS = 120;

    /** A site mid-swap can be briefly unreachable; several failures in a row is a different thing. */
    private const MAX_POLL_FAILURES = 5;

    /** Ordered happy path — used to skip already-passed phases on a resumed run. */
    private const ORDER = [
        S::Requested->value,
        S::ValidatingBackup->value,
        S::PreRestoreBackup->value,
        S::Downloading->value,
        S::Decrypting->value,
        S::Verifying->value,
        S::Maintenance->value,
        S::DatabaseRestore->value,
        S::FileRestore->value,
        S::Cleanup->value,
        S::PostRestoreValidation->value,
        S::Completed->value,
    ];

    private readonly BackupLogger $logger;

    /**
     * @param  Closure(BackupSession):ObjectLayout  $layoutFor  resolves a session's S3 key prefix
     * @param  (Closure(RestoreSession):?int)|null  $preRestoreBackup  takes a safety backup, returns its id
     * @param  (Closure(RestoreSession,RestorePlan):bool)|null  $healthCheck  post-restore site/DB health
     * @param  (Closure(string):void)|null  $fault  test seam: crash injection by phase name
     */
    public function __construct(
        private readonly RestoreSession $session,
        private readonly RestoreClient $client,
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly ChainResolver $resolver,
        private readonly ManifestReader $reader,
        private readonly Closure $layoutFor,
        private readonly ?Closure $preRestoreBackup = null,
        private readonly ?Closure $healthCheck = null,
        ?BackupLogger $logger = null,
        private readonly ?Closure $fault = null,
        // Test seam: lets the poll loop run without spending real seconds.
        private readonly ?Closure $sleeper = null,
    ) {
        $this->logger = ($logger ?? new BackupLogger)->forSession(
            'restore',
            $this->session->id,
            $this->session->site_id,
        );
    }

    public function run(): RestoreSession
    {
        if ($this->session->state->isTerminal()) {
            return $this->session;
        }

        try {
            $target = $this->resolveTarget();
            $plan = $this->buildPlanOrRefuse($target);

            $this->phase(S::ValidatingBackup, 'validating backup + chain', function () use ($target, $plan): void {
                $this->validate($target, $plan);
            });
            $this->phase(S::PreRestoreBackup, 'pre-restore safety backup', fn () => $this->preRestore());
            $this->phase(S::Downloading, 'staging objects to plugin', fn () => $this->download($plan));
            $this->phase(S::Decrypting, 'decrypting staged objects', fn () => $this->decrypt());
            $this->phase(S::Verifying, 'verifying staged objects', fn () => $this->verify($plan));
            $this->phase(S::Maintenance, 'entering maintenance window', fn () => $this->enterMaintenance());
            $this->phase(S::DatabaseRestore, 'applying database restore', fn () => $this->apply($plan));
            $this->phase(S::FileRestore, 'applying file restore', fn () => $this->recordFileRestore());
            $this->phase(S::Cleanup, 'freeing manager-side temp', fn () => $this->cleanup());
            $this->postRestoreValidation($plan);
        } catch (BrokenChainException $e) {
            $this->fail(BackupErrorCode::BrokenChain, $e->getMessage());
        } catch (PreflightFailed $e) {
            // Not a fault: nothing broke and nothing was touched, the host simply cannot take this
            // restore. It carries its own code so the alert names the precondition — "1 GB free,
            // needs 19 GB" — rather than reporting an apply failure for an apply that never ran.
            $this->fail($e->errorCode, $e->getMessage());
        } catch (RestoreRolledBack $e) {
            // Already handled (site returned to pre-apply); session is Failed.
            $this->logger->warning('restore rolled back', ['reason' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->handleMutatingFailure($e);
        }

        return $this->session;
    }

    // ── phase dispatch ───────────────────────────────────────────────────

    private function phase(S $target, string $stage, callable $work): void
    {
        if ($this->orderIndex($this->session->state) > $this->orderIndex($target)) {
            return; // already past this phase on a resumed run
        }
        $this->session->transitionTo($target, $stage);
        $this->session->heartbeat();
        $work();
    }

    private function orderIndex(S $state): int
    {
        $i = array_search($state->value, self::ORDER, true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }

    // ── phases ───────────────────────────────────────────────────────────

    private function resolveTarget(): BackupSession
    {
        $target = $this->session->backupSession;
        if (! $target instanceof BackupSession) {
            throw new RuntimeException("RestoreSession {$this->session->id} has no V2 backup_session target.");
        }

        return $target;
    }

    private function buildPlanOrRefuse(BackupSession $target): RestorePlan
    {
        return RestorePlan::build($target, $this->resolver, $this->reader, $this->session->scope);
    }

    private function validate(BackupSession $target, RestorePlan $plan): void
    {
        // Every backup this restore actually depends on must be whole (_COMPLETE present) before any
        // restore work. For a format/2 backup that is the target alone — its manifest names its
        // objects directly, so the state of the backups it happens to descend from is irrelevant.
        foreach ($this->resolver->membersToVerify($target, $this->reader) as $member) {
            $this->assertComplete($member);
        }

        // Room on the client's server, before a single byte is pushed. A restore that runs the host
        // out of space does it half way through the swap, with the site in maintenance and the
        // rollback itself needing somewhere to put the files it moves back.
        $disk = $this->assertHostHasRoom($plan);

        $this->mergeCheckpoint([
            'token' => $this->token(),
            'disk_preflight' => $disk,
            'plan' => [
                'file_chunks' => count($plan->fileChunks),
                'db_chunks' => count($plan->dbChunks),
                'keep_paths' => count($plan->keepPaths),
                'tombstones' => count($plan->tombstones),
                'mirror_roots' => $plan->mirrorRoots,
                'include_database' => $plan->includeDatabase,
                'include_files' => $plan->includeFiles,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertHostHasRoom(RestorePlan $plan): array
    {
        try {
            $capabilities = $this->client->capabilities();
        } catch (Throwable $e) {
            // Being unable to ask is not the same as being told there is no room. The restore has
            // its own failure modes for a host that cannot be reached, and they report better than
            // a preflight refusing on a question it never got to put.
            $this->logger->warning('could not ask the host about disk space', ['error' => $e->getMessage()]);

            return ['checked' => false, 'reason' => 'the host could not be asked: '.$e->getMessage()];
        }

        return (new RestoreDiskPreflight)->assertRoomFor($plan, $capabilities);
    }

    private function preRestore(): void
    {
        $backupId = $this->preRestoreBackup !== null
            ? ($this->preRestoreBackup)($this->session)
            : null;

        if ($this->mode()->requiresPreRestoreBackup() && $backupId === null) {
            throw new RuntimeException('MIRROR restore requires a pre-restore safety backup, but none was produced.');
        }

        if ($backupId !== null) {
            $this->session->pre_restore_backup_id = $backupId;
            $this->session->save();
            $this->mergeCheckpoint(['pre_restore_backup_id' => $backupId]);
        }
    }

    private function download(RestorePlan $plan): void
    {
        // restore/prepare is idempotent — re-running on resume simply re-asserts the plan.
        $this->client->restorePrepare($this->token(), [
            'mode' => $this->mode()->value,
            'scope' => (array) ($this->session->scope ?? []),
            'mirror_roots' => $plan->mirrorRoots,
            'keep_paths' => $plan->keepPaths,
            'db_tables' => $plan->dbTables,
            'tombstones' => $plan->tombstones,
        ]);

        foreach ($plan->dbChunks as $chunk) {
            $this->stageOne('database', (int) $chunk['seq'], (string) $chunk['key']);
        }
        foreach ($plan->fileChunks as $chunk) {
            $this->stageOne('files', (int) $chunk['seq'], (string) $chunk['key']);
        }
    }

    private function stageOne(string $kind, int $seq, string $key): void
    {
        $id = $kind.':'.$seq;
        $staged = (array) ($this->checkpoint()['staged'] ?? []);
        if (isset($staged[$id])) {
            return; // already staged on a prior run — never re-pull/re-push
        }

        $tmp = (string) WorkDir::temp('v2restore_');
        $plain = null;
        $encrypted = false;

        try {
            $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $tmp]);

            // Decrypt between storage and the host. The plugin has no key and no
            // business having one — it receives the same plaintext zip it would
            // have received before encryption existed, so nothing on the WordPress
            // side changed.
            //
            // Detected from the object's own header rather than from a flag on the
            // session, so a chain that spans the day encryption was turned on
            // restores without anyone having to remember which links are which.
            $source = $tmp;
            if (ObjectCipher::isEncrypted($tmp)) {
                $encrypted = true;
                $keyId = (string) ObjectCipher::keyIdOf($tmp);
                $plain = (string) WorkDir::temp('v2plain_');

                // Authentication failure here is the point of the whole scheme:
                // altered or truncated bytes stop being restorable rather than
                // becoming a site restored from something that is not the backup.
                (new BackupKeyring)->forKeyId($keyId)->decryptFile($tmp, $plain);
                $source = $plain;
            }

            $localSha = (string) hash_file('sha256', $source);
            $result = $this->client->restoreStageChunk($this->token(), $kind, $seq, $source);
        } finally {
            @unlink($tmp);
            if ($plain !== null) {
                @unlink($plain);
            }
        }

        $staged[$id] = [
            'key' => $key,
            'sha256' => $localSha,
            'encrypted' => $encrypted,
            'staged_sha256' => (string) ($result['sha256'] ?? ''),
            'size' => (int) ($result['size'] ?? 0),
        ];
        $this->mergeCheckpoint(['staged' => $staged]);
        $this->session->heartbeat();
    }

    /**
     * Decryption is not a pass over the staged set — it happens per chunk, in
     * stageOne, between pulling the object and pushing it to the host. Doing it
     * here would mean holding every decrypted chunk of a full backup on disk at
     * once, which is exactly what chunking exists to avoid.
     *
     * The phase remains as the place that records what was decrypted, so an
     * operator reading the checkpoint can tell an encrypted restore from a
     * legacy one.
     */
    private function decrypt(): void
    {
        $staged = (array) ($this->checkpoint()['staged'] ?? []);
        $encrypted = count(array_filter($staged, static fn (array $c): bool => (bool) ($c['encrypted'] ?? false)));

        $this->mergeCheckpoint(['decrypted_chunks' => $encrypted]);
    }

    private function verify(RestorePlan $plan): void
    {
        $staged = (array) ($this->checkpoint()['staged'] ?? []);
        $expected = [];
        foreach ($plan->dbChunks as $c) {
            $expected[] = 'database:'.(int) $c['seq'];
        }
        foreach ($plan->fileChunks as $c) {
            $expected[] = 'files:'.(int) $c['seq'];
        }

        foreach ($expected as $id) {
            if (! isset($staged[$id])) {
                throw new RestoreVerifyException("planned chunk {$id} was not staged");
            }
            $local = (string) $staged[$id]['sha256'];
            $remote = (string) $staged[$id]['staged_sha256'];
            if ($remote !== '' && ! hash_equals($local, $remote)) {
                throw new RestoreVerifyException("staged chunk {$id} sha256 mismatch (local {$local} vs staged {$remote})");
            }
        }
    }

    private function enterMaintenance(): void
    {
        // The plugin owns the maintenance flag for the exact apply/swap window (restore/apply).
        // This state records that the manager is about to enter the critical window.
        $this->mergeCheckpoint(['critical_window' => true]);
    }

    /**
     * Perform the swap — detached and polled where the host allows it.
     *
     * Held open as one request this is the step that broke: applying a real site takes minutes, the
     * client gave up at two, and the plugin carried on and finished anyway. The manager then rolled
     * back a restore that had in fact succeeded and recorded the opposite of what happened.
     *
     * So the work is detached and its progress read from the host. A host with neither loopback nor
     * cron answers `async: false` and we do it synchronously, which is still right for a small site.
     */
    private function apply(RestorePlan $plan): void
    {
        $this->fault('before_apply');

        // Asked, not assumed. An older plugin ignores the `async` parameter and applies
        // synchronously, so kicking it would mean timing out after sixty seconds on what we thought
        // was a dispatch, then reaching for a rollback while the apply was still running — strictly
        // worse than never having tried. A host that cannot detach gets the plain call it expects.
        $kick = $this->client->restoreSupportsAsync()
            ? $this->client->restoreApplyAsync($this->token())
            : ['async' => false, 'reason' => 'the site is on a plugin version that applies synchronously'];

        $detached = ($kick['async'] ?? false) === true;

        if ($detached) {
            try {
                $result = $this->awaitApply();
            } catch (ApplyNeverStartedException $e) {
                // Accepted and then dropped. The site has not been touched — the claim is only a
                // status file — so doing it in-band is both safe and better than refusing.
                $this->logger->warning('the detached apply never started; doing it synchronously', [
                    'reason' => $e->getMessage(),
                ]);
                $detached = false;
                $result = $this->client->restoreApply($this->token());
            }
        } else {
            $result = $this->client->restoreApply($this->token());
        }

        $this->fault('after_apply');

        $this->mergeCheckpoint(['apply' => [
            'db' => $result['db'] ?? null,
            'files' => $result['files'] ?? null,
            'applied' => (bool) ($result['applied'] ?? false),
            'async' => $detached,
        ]]);
    }

    /**
     * Poll the host until the detached apply reaches a terminal state.
     *
     * @return array<string, mixed>
     */
    private function awaitApply(): array
    {
        $deadline = now()->addSeconds(self::APPLY_MAX_WAIT_SECONDS);
        $queuedUntil = now()->addSeconds(self::QUEUED_GRACE_SECONDS);
        $consecutiveTransportErrors = 0;

        while (now()->lessThan($deadline)) {
            $this->sleepBetweenPolls();

            try {
                $status = $this->client->restoreStatus($this->token());
                $consecutiveTransportErrors = 0;
            } catch (PluginClientException $e) {
                // 503 is not a failure here — it is the answer.
                //
                // apply() puts the site into maintenance for the swap, and WordPress serves 503 for
                // everything while that file exists, this endpoint included. So the only thing a
                // 503 tells us is that maintenance is on, which is to say the apply is running.
                // Reading it as lost contact is what made the first real async restore give up on a
                // restore that was working, and then fail to roll back for the same reason.
                if ($e->status === 503) {
                    $consecutiveTransportErrors = 0;
                    $this->session->heartbeat();
                    $this->mergeCheckpoint(['apply_phase' => 'maintenance']);

                    continue;
                }

                if (++$consecutiveTransportErrors >= self::MAX_POLL_FAILURES) {
                    throw new RestoreVerifyException(
                        'lost contact with the site while it was applying the restore: '.$e->getMessage()
                    );
                }

                continue;
            } catch (Throwable $e) {
                // A site mid-swap can be briefly unreachable — a restarting php-fpm pool, a
                // connection refused. One failed poll says nothing; several in a row say the host
                // is gone.
                if (++$consecutiveTransportErrors >= self::MAX_POLL_FAILURES) {
                    throw new RestoreVerifyException(
                        'lost contact with the site while it was applying the restore: '.$e->getMessage()
                    );
                }

                continue;
            }

            $state = (string) ($status['state'] ?? '');

            if ($state === 'applied') {
                return ['applied' => true, 'db' => $status['db'] ?? null, 'files' => $status['files'] ?? null];
            }

            if ($state === 'failed') {
                throw new RestoreVerifyException(
                    'the site reported the restore failed: '.(string) ($status['error'] ?? 'no reason given')
                );
            }

            $phase = (string) ($status['phase'] ?? $state);

            // Still only claimed, never started. The host took the kick and then dropped it, so
            // waiting out the full apply deadline would be fifty minutes spent on a restore that
            // never began. Say so, and let the caller fall back to doing it synchronously.
            if ($phase === 'queued' && now()->greaterThan($queuedUntil)) {
                throw new ApplyNeverStartedException(sprintf(
                    'the site accepted the restore but had not started it after %d seconds',
                    self::QUEUED_GRACE_SECONDS,
                ));
            }

            $this->session->heartbeat();
            $this->mergeCheckpoint(['apply_phase' => $phase]);
        }

        throw new RestoreVerifyException(sprintf(
            'the restore was still applying after %d seconds',
            self::APPLY_MAX_WAIT_SECONDS,
        ));
    }

    private function sleepBetweenPolls(): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)(self::POLL_INTERVAL_SECONDS);

            return;
        }

        sleep(self::POLL_INTERVAL_SECONDS);
    }

    private function recordFileRestore(): void
    {
        // apply() performed the DB+file swap atomically inside one maintenance window; this state
        // records the file half for observability. Nothing further to call.
    }

    private function cleanup(): void
    {
        // Manager-side temp is already freed per chunk (pull-and-free). The plugin's rollback data
        // (trash + sambk_old_* tables) is intentionally KEPT until post-restore validation decides.
        $this->fault('at_cleanup');
    }

    private function postRestoreValidation(RestorePlan $plan): void
    {
        $this->phase(S::PostRestoreValidation, 'validating restored site', function () use ($plan): void {
            $this->fault('at_validation');

            $ok = $this->healthCheck !== null
                ? (bool) ($this->healthCheck)($this->session, $plan)
                : true;

            if (! $ok) {
                $this->rollback('post-restore health-check failed');

                throw new RestoreRolledBack('post-restore health-check failed');
            }

            // Confirmed good — let the plugin drop the retained pre-apply tables + trash.
            $this->client->restoreCommit($this->token());
            $this->session->transitionTo(S::Completed, 'restore complete');
        });
    }

    // ── failure handling / rollback ──────────────────────────────────────

    private function handleMutatingFailure(Throwable $e): void
    {
        $state = $this->session->state;

        // If we never started mutating, just fail (site untouched).
        if (! in_array($state, S::mutating(), true)) {
            $this->fail(BackupErrorCode::RestoreApplyFailed, $e->getMessage());

            return;
        }

        // ASK THE SITE BEFORE UNDOING ANYTHING.
        //
        // This is the defect that made the first real restore worse than useless. A transport
        // timeout is indistinguishable from a genuine failure at this level — and it was treated as
        // one. The manager rolled back a restore the plugin had in fact completed, recorded
        // `failed`, and left the old copy of the site on the customer's disk because it never
        // reached commit. The site was correct; only the story about it was wrong.
        //
        // The host knows what happened. One question is enough.
        if ($this->appliedDespiteTheError()) {
            $this->logger->warning('the restore had actually finished; not rolling it back', [
                'error' => $e->getMessage(),
            ]);
            $this->mergeCheckpoint(['recovered_from_transport_failure' => true]);

            return;
        }

        // Genuinely failed: the plugin self-rolls-back a mid-swap failure, but call
        // restore/rollback regardless (idempotent no-op if nothing was applied) so the site is
        // guaranteed at its pre-apply state, then fail.
        try {
            $this->rollback('restore failed: '.$e->getMessage());
        } catch (Throwable $re) {
            $this->logger->error('rollback after failure also failed', ['error' => $re->getMessage()]);
            $this->session->recordError(BackupErrorCode::RestoreApplyFailed, $e->getMessage());
            $this->session->transitionTo(S::Failed);
        }
    }

    /**
     * Did the site finish the restore even though we stopped hearing about it?
     *
     * Deliberately conservative: only an explicit `applied` counts. If the host cannot be reached,
     * or says anything else, we fall through to rollback — the safe direction when the answer is
     * unknown is to put the site back, not to assume it is fine.
     */
    private function appliedDespiteTheError(): bool
    {
        $status = null;

        // Asked more than once, because the likeliest reason we cannot get an answer is that the
        // site is still in maintenance finishing the very apply we are asking about. Giving up on
        // the first 503 would send us to roll back a restore that is midway through succeeding.
        for ($attempt = 0; $attempt < self::MAX_POLL_FAILURES; $attempt++) {
            try {
                $status = $this->client->restoreStatus($this->token());
                break;
            } catch (PluginClientException $e) {
                if ($e->status !== 503) {
                    $this->logger->warning('could not ask the site what happened; assuming the restore failed', [
                        'error' => $e->getMessage(),
                    ]);

                    return false;
                }
            } catch (Throwable $e) {
                $this->logger->warning('could not ask the site what happened; assuming the restore failed', [
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            $this->sleepBetweenPolls();
        }

        if ($status === null || (string) ($status['state'] ?? '') !== 'applied') {
            return false;
        }

        // It finished. Complete the transaction the timeout interrupted — dropping the retained
        // pre-apply tables and the trash directory — so the customer is not left carrying a second
        // copy of their own site.
        try {
            $this->client->restoreCommit($this->token());
        } catch (Throwable $e) {
            // The restore stands either way; the leftovers are swept by the plugin's own cron.
            $this->logger->warning('the restore finished but could not be committed', [
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function rollback(string $reason): void
    {
        $this->session->recordError(BackupErrorCode::PostRestoreValidationFailed, $reason);
        $this->session->transitionTo(S::Rollback, 'rolling back');
        $this->session->heartbeat();

        // 1) Reverse the plugin's journal + DB swap (return to pre-apply).
        $reversed = true;
        $rollbackError = null;
        try {
            $this->client->restoreRollback($this->token());
        } catch (Throwable $e) {
            $reversed = false;
            $rollbackError = $e->getMessage();
            $this->logger->error('plugin rollback failed', ['error' => $rollbackError]);
        }

        // 2) Backstop: a pre-restore safety backup was taken for exactly this case. The dispatcher
        //    can restore it if the journaled rollback was insufficient (recorded for the operator).
        $this->mergeCheckpoint([
            'rolled_back' => $reversed,
            'rollback_reason' => $reason,
            'rollback_error' => $rollbackError,
        ]);

        // Say which of the two things happened. Claiming "rolled back to pre-apply" after the site
        // refused the rollback is how somebody comes to believe a half-swapped site is safe, walks
        // away, and finds out later. When the reversal did not happen, the stage says so and points
        // at the pre-restore safety backup, which is the remaining way back.
        $this->session->transitionTo(
            S::Failed,
            $reversed
                ? 'rolled back to pre-apply'
                : 'ROLLBACK FAILED — the site may be mid-swap; restore the pre-restore safety backup',
        );
    }

    private function fail(BackupErrorCode $code, string $message): void
    {
        $this->session->recordError($code, $message);
        if ($this->session->state !== S::Failed) {
            $this->session->transitionTo(S::Failed);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function assertComplete(BackupSession $session): void
    {
        $layout = ($this->layoutFor)($session);
        try {
            $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $layout->completeMarker()]);
        } catch (Throwable $e) {
            throw BrokenChainException::missingManifest($session->id, '_COMPLETE marker missing: '.$e->getMessage());
        }
    }

    private function mode(): RestoreMode
    {
        return RestoreMode::tryFrom((string) $this->session->mode) ?? RestoreMode::SafeMerge;
    }

    private function token(): string
    {
        $cp = $this->checkpoint();
        if (isset($cp['token']) && $cp['token'] !== '') {
            return (string) $cp['token'];
        }
        $token = 'restore_'.$this->session->id.'_'.substr((string) $this->session->idempotency_key, 0, 8);
        $token = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $token);
        $this->mergeCheckpoint(['token' => $token]);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkpoint(): array
    {
        $this->session->refresh();

        return $this->session->checkpoint ?? [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function mergeCheckpoint(array $patch): void
    {
        $this->session->refresh();
        $this->session->checkpoint = array_merge($this->session->checkpoint ?? [], $patch);
        $this->session->save();
    }

    private function fault(string $phase): void
    {
        if ($this->fault !== null) {
            ($this->fault)($phase);
        }
    }
}
