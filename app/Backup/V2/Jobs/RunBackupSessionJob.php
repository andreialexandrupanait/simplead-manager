<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Support\BackupLogger;
use App\Backup\V2\Support\BackupV2Gate;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

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

    public int $tries = 1;

    public function __construct(public readonly int $backupSessionId) {}

    public function uniqueId(): string
    {
        return 'backup-v2-session-'.$this->backupSessionId;
    }

    public function handle(): void
    {
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

        // Resolve the site's REAL S3 destination + per-site plugin credentials.
        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            throw new RuntimeException("No storage destination is configured for site {$session->site_id}.");
        }

        $s3 = S3ClientFactory::forDestination($destination);
        $client = SimpleadBackupClient::forSite($site, $logger);

        $layout = ObjectLayout::forBackup(
            clientId: $site->getAttribute('client_id') ?? 0,
            siteId: $session->site_id,
            backupId: $session->backup_id ?? $session->id,
        );

        (new BackupRunner(
            session: $session,
            client: $client,
            s3: $s3->client(),
            bucket: $s3->bucket(),
            layout: $layout,
            logger: $logger,
        ))->run();
    }
}
