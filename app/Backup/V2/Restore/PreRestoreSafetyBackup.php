<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Orchestration\BackupEnvelope;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\PluginClient;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Site;
use App\Models\StorageDestination;
use Aws\S3\S3Client;
use Illuminate\Support\Str;

/**
 * REAL pre-restore safety backup handed to RestoreRunner as its `preRestoreBackup`
 * closure. Before a restore mutates the live site it takes a FULL backup of the
 * current state through the ordinary BackupRunner and returns the id of the resulting
 * `backups` row, which the RestoreRunner records as the restore's
 * pre_restore_backup_id. For a MIRROR restore this is MANDATORY — the runner refuses
 * to proceed if this returns null — and it is the backstop the guaranteed rollback
 * falls back to if the plugin's journaled swap is ever insufficient.
 *
 * It reuses the SAME resolved transport the restore job already built (the site's
 * plugin client + its real S3 destination), so the safety backup lands in the site's
 * own storage exactly like any other backup.
 *
 * It also goes through BackupEnvelope like any other backup, which it did not before, and both
 * halves of that mattered on the first live attempt:
 *
 *   - it returned the BackupSession id into a column that references `backups`, so the write was
 *     refused by the foreign key and the restore stopped — correctly, before touching the site,
 *     but only because the constraint existed to catch it;
 *   - the session was created raw, with no `backups` row at all, so the safety copy was invisible
 *     in the site's backup list, uncounted by storage, and outside retention. A rollback point
 *     nobody can see is not much of a rollback point.
 */
class PreRestoreSafetyBackup
{
    public function __construct(
        private readonly Site $site,
        private readonly PluginClient $client,
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly ?BackupLogger $logger = null,
        private readonly ?StorageDestination $destination = null,
    ) {}

    public function __invoke(RestoreSession $restore): ?int
    {
        $destination = $this->destination ?? StorageDestination::resolveForSite($this->site);
        if (! $destination instanceof StorageDestination) {
            $this->logger?->warning('pre-restore safety backup has nowhere to go', [
                'site_id' => $this->site->id,
            ]);

            return null;
        }

        $scope = ['database' => true, 'files' => true];

        // The visible row first, exactly as the scheduler does it: a safety copy that does not
        // appear anywhere is a rollback point nobody can find on the day they need it.
        $backup = (new BackupEnvelope)->open($this->site, $destination, 'full', 'pre_restore', $scope);

        $session = BackupSession::create([
            'site_id' => $this->site->id,
            'backup_id' => $backup->id,
            'type' => 'full',
            'scope' => $scope,
            'resource_profile' => (string) config('backup_v2.default_profile', 'low_impact'),
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'pre-restore-'.$restore->id.'-'.Str::random(16),
            'format_version' => (string) config('backup_v2.format_version', 'simplead-backup/2'),
        ]);

        $this->runBackup($session);

        $session->refresh();

        // Only a completed safety backup counts. If it did not complete, return null
        // so the runner refuses a MIRROR restore rather than mutating without a net.
        if ($session->state !== BackupSessionState::Completed) {
            $this->logger?->warning('pre-restore safety backup did not complete', [
                'backup_session_id' => $session->id,
                'backup_id' => $backup->id,
                'state' => $session->state->value,
            ]);

            return null;
        }

        // The `backups` row, not the session: restore_sessions.pre_restore_backup_id references
        // `backups`, and handing it a session id is a foreign key violation — which is exactly how
        // this was found, on the first live attempt, one step before the site would have been
        // touched.
        return (int) $backup->id;
    }

    /**
     * Take the backup. A seam, so the bookkeeping around it can be tested without standing up an
     * object store — the backup itself is covered against real storage by BackupRunnerE2ETest.
     */
    protected function runBackup(BackupSession $session): void
    {
        (new BackupRunner(
            session: $session,
            client: $this->client,
            s3: $this->s3,
            bucket: $this->bucket,
            layout: SessionLayoutResolver::for($session, $this->site),
            logger: $this->logger,
        ))->run();
    }
}
