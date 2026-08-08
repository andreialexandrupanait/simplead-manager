<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Backup\V2\Chain\ChainPlanner;
use App\Backup\V2\Orchestration\SessionActions;
use App\Backup\V2\Support\BackupV2Gate;
use App\Enums\BackupEngine;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use RuntimeException;

/**
 * The one place that starts a backup.
 *
 * There were fourteen. Every screen with a "back up now" button dispatched
 * App\Jobs\CreateBackup itself, and only two of them — the buttons on the site's
 * own Backups page — ever asked which engine the site runs. So the fleet page's
 * "Backup All Sites", the site overview's "Run Backup Now" card, the sites list,
 * the dashboard, the notification dropdown, onboarding, the operations registry
 * and even RunBackupNowCommand (a V2 console command) all started the OLD engine
 * on a site the scheduler had already moved to the new one.
 *
 * That is not a cosmetic inconsistency. The two engines take the site's operation
 * lock under different refs on purpose, so nothing stops them running together:
 * one builds an archive on the host while the other chunks the same filesystem and
 * dumps the same database. And it is invisible from the interface — the button
 * says "Backup", the backup appears in the history, and only the `engine` column
 * records which one did it.
 *
 * Routing still goes through BackupV2Gate rather than hard-wiring V2, because the
 * per-site rollback to the old engine is a documented lever (FleetMigration has a
 * button for it) and it stays real until the engine concept itself is removed.
 * What changes here is that the choice is made ONCE, in one place, instead of
 * being forgotten in twelve.
 */
final class BackupLauncher
{
    public function __construct(
        private readonly SessionActions $sessions = new SessionActions,
    ) {}

    /**
     * Start a backup for this site now, on whichever engine it actually runs.
     *
     * Throws rather than returning null on failure: every caller has a message to
     * show a person, and a silent no-op reads as "queued" on screen while nothing
     * was queued at all. SessionActions already throws for a missing destination
     * and for quota, so this matches what the new engine does.
     *
     * @param  string  $type  full|incremental|database|files
     * @param  int  $delaySeconds  stagger, for bulk runs — the row exists immediately and reads "queued"
     *
     * @throws RuntimeException when the site has nowhere to write
     */
    public function launch(Site $site, string $type = 'full', string $trigger = 'manual', int $delaySeconds = 0): Backup
    {
        if (BackupV2Gate::engineFor($site) === BackupEngine::V2) {
            return $this->launchV2($site, $type, $trigger, $delaySeconds);
        }

        return $this->launchV1($site, $type, $trigger, $delaySeconds);
    }

    private function launchV2(Site $site, string $type, string $trigger, int $delaySeconds): Backup
    {
        // A person who clicked "Incremental" has already decided; the planner is
        // asked only for the chain base, and only when it agrees an incremental is
        // possible. Every other type is passed through untouched — including
        // `database`, which the engine has always been able to scope
        // (SessionActions::defaultScope) and which nothing ever asked it for.
        $useChain = false;
        $plan = ['full_base_id' => null, 'chain_position' => null];

        if ($type === 'incremental') {
            $plan = (new ChainPlanner)->planFor($site);
            $useChain = $plan['type'] === 'incremental';
        }

        $session = $this->sessions->startBackup($site, $useChain ? 'incremental' : $type, [
            'trigger' => $trigger,
            'delay_seconds' => $delaySeconds,
            'full_base_id' => $useChain ? $plan['full_base_id'] : null,
            'chain_position' => $useChain ? $plan['chain_position'] : null,
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

    private function launchV1(Site $site, string $type, string $trigger, int $delaySeconds): Backup
    {
        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            throw new RuntimeException('No storage destination is configured for this site.');
        }

        // An engine-specific precondition, kept with the engine. The old engine
        // replays an incremental against a manifest, so without one there is
        // nothing to diff against; the new engine asks its own ChainPlanner and
        // falls back to a full on its own. Callers should not have to know which
        // of those two is true for this site.
        if ($type === 'incremental' && ! $this->hasV1ChainBase($site)) {
            throw new RuntimeException('No full backup with manifest found. Please create a full backup first.');
        }

        $backup = Backup::create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'type' => $type,
            'trigger' => $trigger,
            'status' => 'pending',
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Backup queued, waiting to start...',
            'includes_database' => $type !== 'files',
            'includes_files' => $type !== 'database',
            'wp_version' => $site->wp_version,
            'php_version' => $site->php_version,
            'db_size_mb' => $site->db_size_mb,
            'started_at' => now(),
        ]);

        try {
            // Two jobs, not one. Sending an incremental to CreateBackup would
            // quietly produce a full — the same class of silent promotion this
            // service exists to end.
            $pending = $type === 'incremental'
                ? CreateIncrementalBackup::dispatch($site, $trigger, $destination->id, $backup->id)
                : CreateBackup::dispatch($site, $type, $trigger, $destination->id, $backup->id);

            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        } catch (\Throwable $e) {
            // Do not leave a row that will never run sitting `pending` in the
            // site's history — it would read as a backup in progress forever.
            $backup->update([
                'status' => 'failed',
                'stage' => 'failed',
                'error_message' => 'Failed to dispatch job: '.$e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }

        return $backup;
    }

    /** The old engine can only build an incremental on top of a manifest it wrote. */
    private function hasV1ChainBase(Site $site): bool
    {
        return Backup::where('site_id', $site->id)
            ->where('status', 'completed')
            ->whereNotNull('manifest_path')
            ->exists();
    }
}
