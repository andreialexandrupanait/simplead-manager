<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\StateMachine\BackupStateMachine;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Support\BackupLogger;
use App\Backup\V2\Support\BackupV2Gate;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\RetentionService;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Queued entry point that runs (or resumes) one BackupSession through BackupRunner.
 *
 * SAFETY: inert in production. handle() hard-refuses via BackupV2Gate::allowsSite()
 * unless config('backup_v2.enabled') is true AND the site is on the
 * config('backup_v2.site_ids') allowlist — both default to off/empty. The V2
 * scheduler that would enqueue it is itself flag-gated and off by default.
 *
 * PRODUCTION wiring: the S3 client is resolved from the site's real
 * StorageDestination (S3ClientFactory::forDestination, decrypting creds exactly like
 * the V1 S3Driver) and the plugin client from the Site row
 * (SimpleadBackupClient::forSite). No lab creds on the production path.
 */
final class RunBackupSessionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * A crashed attempt is resumable, so it is worth resuming.
     *
     * This was 1, which meant the first dropped connection or killed worker ended
     * the backup for the night — the checkpoint, the confirmed objects and the
     * multipart parts all survived, and nothing ever came back for them. Each
     * retry re-enters BackupRunner::run(), which skips every phase already past
     * and every object already confirmed, so a retry costs only the work that was
     * actually lost.
     */
    public int $tries = 3;

    /** @var array<int, int> seconds between attempts */
    public array $backoff = [120, 600];

    /**
     * @param  string|null  $heldLockToken  the caller's site-operation lock, when it already holds one
     */
    public function __construct(
        public readonly int $backupSessionId,
        public readonly ?string $heldLockToken = null,
    ) {
        // The queue that was tuned for this work, not the general one.
        //
        // Declaring no queue put every V2 backup on `default`, which Horizon serves from
        // supervisor-general — three workers at 512 MB, shared with reports, security scans and
        // performance checks. Meanwhile supervisor-backups, configured with a gigabyte per worker
        // and an hour of timeout precisely because backups are the memory hog, sat empty.
        //
        // Invisible while one site was on V2. With the whole fleet moved, a night of backups would
        // hold all three general workers for hours and everything else would queue behind them.
        $this->onQueue('backups');
    }

    /**
     * The unique lock releases after this even if the worker is SIGKILLed.
     * Without it Laravel's default leaves the key behind and a resume silently
     * no-ops until it ages out.
     */
    public int $uniqueFor = 3600;

    /**
     * Unique per SITE, not per session.
     *
     * Keyed on the session id, two sessions for the same site were two different
     * keys, so nothing stopped both from running at once — each pulling chunks
     * and dumping the database of the same WordPress install. The site is the
     * resource under contention, so the site is what the key names.
     */
    public function uniqueId(): string
    {
        $siteId = BackupSession::query()->whereKey($this->backupSessionId)->value('site_id');

        return 'backup-v2-site-'.($siteId ?? 'unknown-'.$this->backupSessionId);
    }

    public function handle(): void
    {
        // The container ships php.ini at 256M while supervisor-backups budgets a gigabyte per
        // worker — a large site's file inventory plus one buffered HTTP response is enough to
        // hit the smaller of the two, and the worker dies without cleanup (motivonti.ro,
        // nightly, OOM in guzzle). Same raise the V1 RestoreBackup job already does.
        ini_set('memory_limit', '1G');

        $session = BackupSession::findOrFail($this->backupSessionId);
        $site = $session->site;
        if (! $site instanceof Site) {
            throw new RuntimeException("BackupSession {$session->id} has no site.");
        }

        $logger = (new BackupLogger)->forSession('backup', $session->id, $session->site_id);

        // Hard guard: the engine may only run when enabled AND the site is on the
        // allowlist. With default flags this refuses every site (zero prod impact).
        if (! BackupV2Gate::allowsSite((int) $session->site_id)) {
            $logger->warning('backup refused: site not enabled/allowlisted for V2', [
                'enabled' => BackupV2Gate::enabled(),
                'site_id' => $session->site_id,
            ]);

            throw new RuntimeException(
                "Backup engine V2 refused site {$session->site_id}: not enabled or not on backup_v2.site_ids allowlist."
            );
        }

        // Serialise against the other engine. V1's backup, incremental, restore
        // and safe-update jobs all take this lock; V2 took nothing, so the two
        // could run on the same WordPress install at once — one building an
        // archive on the host while the other chunked the same filesystem and
        // dumped the same database. Acquired here rather than when the session
        // is created because a session can sit queued for minutes, and a
        // two-hour lock held across queue latency blocks V1 for a job that has
        // not started.
        //
        // The ref format is the convention BackupJobTrait::failed() matches on,
        // so V1 can never force-release a V2 lock and vice versa.
        // A safe update holds this site's lock for the whole operation and takes
        // its rollback point inside it, so asking for the lock again is asking
        // the caller to wait for itself. When the caller hands over a token it
        // still owns, that token is used and — crucially — NOT released here:
        // the update is not finished, and dropping its lock mid-flight would let
        // a scheduled backup start on top of a site being updated.
        $borrowedLock = $this->heldLockToken !== null
            && SiteOperationLock::isOwnedBy((int) $session->site_id, $this->heldLockToken);

        $token = $borrowedLock
            ? $this->heldLockToken
            : SiteOperationLock::acquire(
                (int) $session->site_id,
                SiteOperationLock::OPERATION_BACKUP,
                self::class.':'.$session->id,
            );

        if ($token === null) {
            $holder = SiteOperationLock::current((int) $session->site_id);
            $busyWith = $holder['operation'] ?? 'another operation';

            $logger->warning('backup deferred: site busy', ['holder' => $busyWith]);

            // Parked, not failed. The checkpoint is untouched and the recovery
            // sweep re-dispatches it once next_retry_at passes.
            if (BackupStateMachine::canTransition($session->state, BackupSessionState::RetryWait)) {
                $session->next_retry_at = now()->addMinutes(5);
                $session->transitionTo(BackupSessionState::RetryWait, "site busy with {$busyWith}");
            }

            return;
        }

        try {
            // Resolve the site's REAL S3 destination + per-site plugin credentials.
            $destination = StorageDestination::resolveForSite($site);
            if ($destination === null) {
                throw new RuntimeException("No storage destination is configured for site {$session->site_id}.");
            }

            $s3 = S3ClientFactory::forDestination($destination);
            $client = SimpleadBackupClient::forSite($site, $logger);

            $layout = SessionLayoutResolver::for($session, $site);

            (new BackupRunner(
                session: $session,
                client: $client,
                s3: $s3->client(),
                bucket: $s3->bucket(),
                layout: $layout,
                logger: $logger,
                // Without this an incremental was an incremental in name only.
                // The runner asks the provider for the chain's current file state
                // and hands it to the plugin, which returns a plan covering only
                // what changed plus tombstones for what was deleted. Unwired, the
                // provider was null, resolveBaseState() returned null, and the
                // plugin swept every file on the host exactly as for a full.
                //
                // It now also feeds the manifest this run writes: unchanged files
                // are carried forward pointing at the objects they already live in,
                // so the restore point stands on its own.
                baseStateProvider: static function (BackupSession $s) use ($s3): ?array {
                    if ($s->type !== 'incremental') {
                        return null;
                    }

                    $resolver = new ChainResolver;

                    return $resolver->materialize(
                        $resolver->baseChainFor($s),
                        // Each chain member's prefix comes from the member's own
                        // frozen object_prefix, never recomputed — a base written
                        // under an older layout is still found where it actually
                        // is.
                        new S3ManifestReader(
                            $s3->readClient(),
                            $s3->bucket(),
                            static fn (BackupSession $member) => SessionLayoutResolver::for($member),
                        ),
                    );
                },
                // upload_verifying re-reads every object it just wrote. Without a
                // retry here a single Hetzner throttle discarded a finished backup.
                readS3: $s3->readClient(),
            ))->run();
        } finally {
            // Give back only what we took. A borrowed lock belongs to the
            // operation that is still running around us.
            if (! $borrowedLock) {
                SiteOperationLock::release((int) $session->site_id, $token);
            }
        }

        // The archive a person can actually use is built straight away, so it is waiting rather
        // than being started by whoever needs it on the worst day of their week. It reads only from
        // storage — the client's site is not touched — and it is verified before it is published,
        // so the download button can only ever hand over something that opened and parsed.
        //
        // Dispatched after the lock is released: this does not go near the site, and holding the
        // site's operation lock through it would block the next backup for no reason.
        if ($session->refresh()->state === BackupSessionState::Completed) {
            BuildPortablePackageJob::dispatch((int) $session->id);
            $this->applyRetention($site, $destination, $logger);
        }
    }

    /**
     * Retire what this site no longer needs to keep.
     *
     * V1 called this at the end of every successful backup — CreateBackup and CreateIncrementalBackup
     * both do — and V2 called it nowhere. So on the night the fleet moved to the new engine the
     * per-site policy stopped being applied at all: `SessionActions::delete()` routes a MANUAL delete
     * through RetentionService::purge(), which covers the console button and nothing else. Nothing
     * has aged out of storage since. It does not announce itself — a backup module whose retention
     * quietly does nothing looks exactly like one whose retention is working, right up until the bill
     * or the bucket says otherwise.
     *
     * RetentionService already understands this engine: deleteBackup() branches on
     * `engine === V2` and removes the whole object prefix, precisely so a prefix is never
     * handed to DeleteObject as if it were a key. Only the call was missing.
     *
     * Deliberately after the site lock is released and after the portable package is queued: this
     * reads storage and the database, never the client's site, and holding the site's lock through
     * it would delay the next backup for no reason.
     *
     * Failures here are logged, never thrown. A backup that succeeded must not be reported as failed
     * because the cleanup that follows it could not reach a bucket — the restore point is already
     * written and verified, and retention retries by its nature on the next run.
     */
    private function applyRetention(Site $site, StorageDestination $destination, BackupLogger $logger): void
    {
        try {
            app(RetentionService::class)->apply($site, $destination);
        } catch (Throwable $e) {
            $logger->warning('retention pass failed after a successful backup', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The last thing that runs when the job dies.
     *
     * BackupRunner now records its own failures, but it can only speak for what
     * happens inside it. A session whose destination cannot be resolved, whose
     * plugin credentials are missing, or that is killed by the worker before the
     * runner is even constructed would otherwise sit in `requested` forever —
     * and with tries = 1 there is no second attempt to notice.
     *
     * Idempotent: if the runner already reached a terminal state this is a no-op,
     * so the more specific error it recorded is not overwritten by a generic one.
     */
    public function failed(?Throwable $e): void
    {
        $session = BackupSession::find($this->backupSessionId);
        if (! $session instanceof BackupSession) {
            return;
        }

        // failed() runs on a fresh instance, so the runtime token is gone.
        // Release by ref match: the holder may be a restore or a safe update
        // running perfectly well, and taking its lock away would let a backup
        // start on top of it.
        $holder = SiteOperationLock::current((int) $session->site_id);
        if ($holder !== null && str_starts_with($holder['ref'], self::class.':')) {
            SiteOperationLock::forceRelease((int) $session->site_id);
        }

        if ($session->state->isTerminal()) {
            return;
        }

        $session->recordError(
            BackupErrorCode::EngineError,
            $e?->getMessage() ?? 'backup job failed without an exception',
        );

        if (BackupStateMachine::canTransition($session->state, BackupSessionState::Failed)) {
            $session->transitionTo(BackupSessionState::Failed, 'job failed: '.class_basename($e ?? 'unknown'));
        }
    }
}
