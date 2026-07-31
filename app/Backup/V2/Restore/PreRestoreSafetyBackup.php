<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\PluginClient;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Site;
use Aws\S3\S3Client;
use Illuminate\Support\Str;

/**
 * REAL pre-restore safety backup handed to RestoreRunner as its `preRestoreBackup`
 * closure. Before a restore mutates the live site it takes a FULL backup of the
 * current state through the ordinary BackupRunner and returns the resulting
 * BackupSession id, which the RestoreRunner records as the restore's
 * pre_restore_backup_id. For a MIRROR restore this is MANDATORY — the runner refuses
 * to proceed if this returns null — and it is the backstop the guaranteed rollback
 * falls back to if the plugin's journaled swap is ever insufficient.
 *
 * It reuses the SAME resolved transport the restore job already built (the site's
 * plugin client + its real S3 destination), so the safety backup lands in the site's
 * own storage exactly like any other backup.
 */
final class PreRestoreSafetyBackup
{
    public function __construct(
        private readonly Site $site,
        private readonly PluginClient $client,
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly ?BackupLogger $logger = null,
    ) {}

    public function __invoke(RestoreSession $restore): ?int
    {
        $session = BackupSession::create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => (string) config('backup_v2.default_profile', 'low_impact'),
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'pre-restore-'.$restore->id.'-'.Str::random(16),
            'format_version' => (string) config('backup_v2.format_version', 'simplead-backup/1'),
        ]);

        $layout = SessionLayoutResolver::for($session, $this->site);

        (new BackupRunner(
            session: $session,
            client: $this->client,
            s3: $this->s3,
            bucket: $this->bucket,
            layout: $layout,
            logger: $this->logger,
        ))->run();

        $session->refresh();

        // Only a completed safety backup counts. If it did not complete, return null
        // so the runner refuses a MIRROR restore rather than mutating without a net.
        if ($session->state !== BackupSessionState::Completed) {
            $this->logger?->warning('pre-restore safety backup did not complete', [
                'backup_session_id' => $session->id,
                'state' => $session->state->value,
            ]);

            return null;
        }

        return $session->id;
    }
}
