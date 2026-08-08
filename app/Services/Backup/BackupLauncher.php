<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Backup\V2\Chain\ChainPlanner;
use App\Backup\V2\Orchestration\SessionActions;
use App\Models\Backup;
use App\Models\Site;
use RuntimeException;

/**
 * The one place that starts a backup.
 *
 * There were fourteen. Every screen with a "back up now" button dispatched a job
 * itself, and only the two on a site's own Backups page ever asked which engine
 * the site ran — so the fleet page's "Backup All Sites", the site overview card,
 * the sites list, the dashboard, the notification retry, onboarding, the
 * operations registry and a console command under app/Backup/V2 all started the
 * engine the fleet had already left.
 *
 * There is one engine now, so the routing that used to live here is gone with it.
 * What the service is still for is the shape of the operation: the chain is
 * consulted only when an incremental was actually asked for, the trigger and the
 * stagger travel with the request, and every caller gets the same errors for the
 * same reasons — one place to change when that has to change again.
 */
final class BackupLauncher
{
    public function __construct(
        private readonly SessionActions $sessions = new SessionActions,
    ) {}

    /**
     * Start a backup for this site now.
     *
     * Throws rather than returning null on failure: every caller has a message to
     * show a person, and a silent no-op reads as "queued" on screen while nothing
     * was queued at all.
     *
     * @param  string  $type  full|incremental|database|files
     * @param  int  $delaySeconds  stagger, for bulk runs — the row exists immediately and reads "queued"
     *
     * @throws RuntimeException when the site has nowhere to write
     */
    public function launch(Site $site, string $type = 'full', string $trigger = 'manual', int $delaySeconds = 0): Backup
    {
        return $this->start($site, $type, $trigger, delaySeconds: $delaySeconds);
    }

    /**
     * Take a backup NOW, on this process, and do not return until it is over.
     *
     * A safe update is the one caller that cannot queue. It holds the site's
     * operation lock for the whole operation, takes a rollback point inside it,
     * and refuses to touch the site unless that point reached `completed` — which
     * is the entire value of the feature. Queueing would hand the work to a
     * worker that then waits for the lock the caller is holding.
     *
     * The row is returned refreshed: the caller has to be able to read the real
     * outcome, not the row as it looked before the work started.
     *
     * @param  string|null  $heldLockToken  the caller's lock, which stays the caller's
     */
    public function launchSync(Site $site, string $type, string $trigger, ?string $heldLockToken = null): Backup
    {
        return $this->start($site, $type, $trigger, sync: true, heldLockToken: $heldLockToken)->refresh();
    }

    private function start(
        Site $site,
        string $type,
        string $trigger,
        int $delaySeconds = 0,
        bool $sync = false,
        ?string $heldLockToken = null,
    ): Backup {
        // A person who clicked "Incremental" has already decided; the planner is
        // asked only for the chain base, and only when it agrees an incremental is
        // possible. Every other type passes through untouched — including
        // `database`, which the engine has always been able to scope
        // (SessionActions::defaultScope) and which nothing used to ask it for.
        $useChain = false;
        $plan = ['full_base_id' => null, 'chain_position' => null];
        $effectiveType = $type;

        if ($type === 'incremental') {
            $plan = (new ChainPlanner)->planFor($site);
            $useChain = $plan['type'] === 'incremental';

            // The planner's answer, not the request. A site with nothing to build
            // on gets a full — asking for an incremental against no base is a
            // question with no valid answer, and the planner already knows it.
            $effectiveType = $plan['type'];
        }

        $session = $this->sessions->startBackup($site, $effectiveType, [
            'trigger' => $trigger,
            'delay_seconds' => $delaySeconds,
            'full_base_id' => $useChain ? $plan['full_base_id'] : null,
            'chain_position' => $useChain ? $plan['chain_position'] : null,
            'sync' => $sync,
            'held_lock_token' => $heldLockToken,
        ]);

        $backup = $session->backup;

        if (! $backup instanceof Backup) {
            // startBackup() opens the row before the session precisely so this
            // cannot happen; say so plainly rather than returning something the
            // caller will dereference.
            throw new RuntimeException('The backup session was created without a backup row.');
        }

        return $backup;
    }
}
