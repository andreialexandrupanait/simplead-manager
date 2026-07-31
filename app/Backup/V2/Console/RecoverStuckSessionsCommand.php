<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Jobs\RunBackupSessionJob;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\StateMachine\BackupStateMachine;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The attendant for sessions nobody is coming back for.
 *
 * Two jobs, both of which the engine needs and neither of which anything else
 * does:
 *
 * DEAD SESSIONS. A SIGKILLed worker runs neither the runner's error path nor
 * the job's failed() hook, so the session stays in whatever phase it died in
 * with a stale heartbeat and the site lock held for its full two-hour TTL. The
 * V1 stuck sweep cannot do this — it "recovers" a backup by dispatching
 * CreateBackup, which on a V2 site means putting the old engine on top of the
 * new one — so this is deliberately separate rather than a filter on that one.
 *
 * PARKED SESSIONS. A session parks in retry_wait when the site is busy or the
 * engine hits an error it can resume from. That state is only honest if
 * something comes back for it; without an attendant, retry_wait is the same
 * silent stall as a session frozen at `uploading`, just with a nicer name.
 *
 * The threshold must exceed RunBackupSessionJob::$timeout (3600s) — a backup
 * that is merely slow is not a backup that is dead.
 */
class RecoverStuckSessionsCommand extends Command
{
    /** Must exceed RunBackupSessionJob::$timeout (3600s) with margin. */
    public const STALE_AFTER_MINUTES = 75;

    protected $signature = 'backups:recover-stuck-sessions {--dry-run : Report without changing anything}';

    protected $description = 'Fail V2 backup sessions whose worker died, and re-dispatch the ones parked for retry';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $failed = $this->failDeadSessions($dryRun);
        $resumed = $this->resumeParkedSessions($dryRun);

        if ($failed === 0 && $resumed === 0) {
            $this->info('No stuck or parked backup sessions.');
        }

        return self::SUCCESS;
    }

    private function failDeadSessions(bool $dryRun): int
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        // heartbeat_at is refreshed at the start of every phase. A session with
        // no heartbeat at all never got past `requested`, so started_at stands
        // in — otherwise a session whose worker died before the first phase
        // would sit in the queue forever and never be seen here.
        $dead = BackupSession::query()
            ->whereIn('state', array_map(
                static fn (S $s): string => $s->value,
                [...S::processing(), S::Cancelling],
            ))
            ->where(function ($q) use ($cutoff) {
                $q->where('heartbeat_at', '<', $cutoff)
                    ->orWhere(fn ($q2) => $q2->whereNull('heartbeat_at')->where('started_at', '<', $cutoff));
            })
            ->get();

        foreach ($dead as $session) {
            $silentFor = (int) ($session->heartbeat_at ?? $session->started_at ?? now())->diffInMinutes(now());
            $this->warn("Session {$session->id} (site {$session->site_id}): silent for {$silentFor}m in {$session->state->value}");

            if ($dryRun) {
                continue;
            }

            $message = "Backup worker died without cleanup — no heartbeat for {$silentFor} minutes.";
            $session->recordError(BackupErrorCode::EngineError, $message);

            if (BackupStateMachine::canTransition($session->state, S::Failed)) {
                // transitionTo raises the alert and syncs the `backups` row.
                $session->transitionTo(S::Failed, 'worker presumed dead');
            }

            $this->releaseLock($session);
            Log::warning("RecoverStuckSessions: session {$session->id} failed after {$silentFor}m of silence");
        }

        return $dead->count();
    }

    private function resumeParkedSessions(bool $dryRun): int
    {
        $parked = BackupSession::query()
            ->where('state', S::RetryWait->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->get();

        foreach ($parked as $session) {
            $this->info("Session {$session->id} (site {$session->site_id}): due for retry, re-dispatching");

            if ($dryRun) {
                continue;
            }

            // Re-dispatch rather than transition: the runner puts the session
            // back on the linear path itself, from the phase recorded in its
            // checkpoint, and skips every object already confirmed.
            RunBackupSessionJob::dispatch($session->id);
        }

        return $parked->count();
    }

    /**
     * Ownership-checked release, never a blind force: the holder may be a
     * restore or a safe update running perfectly well, and taking its lock away
     * would let a backup start on top of it.
     */
    private function releaseLock(BackupSession $session): void
    {
        $holder = SiteOperationLock::current((int) $session->site_id);
        if ($holder !== null && str_starts_with($holder['ref'], RunBackupSessionJob::class.':')) {
            SiteOperationLock::forceRelease((int) $session->site_id);
            $this->info("Released the site lock for site {$session->site_id}.");
        }
    }
}
