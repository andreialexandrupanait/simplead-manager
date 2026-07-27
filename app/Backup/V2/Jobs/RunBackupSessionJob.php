<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Site;
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
 * SAFETY: inert in production. Nothing dispatches this unless config('backup_v2.*')
 * flags are on and the site is whitelisted (see BackupV2ServiceProvider / config).
 * The V2 scheduler that would enqueue it is itself flag-gated and off by default.
 *
 * PRODUCTION wiring (TODO P2/P3): resolve the S3 destination + per-site plugin
 * credentials from the site's StorageDestination instead of the lab factory
 * (S3ClientFactory::forDestination / SimpleadBackupClient::forSite decrypting real
 * creds). The lab path proves the orchestration; the prod resolvers are the
 * remaining seam.
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
        if (! (bool) config('backup_v2.enabled', false)) {
            // Hard guard: never run against a site while the engine is disabled.
            throw new RuntimeException('Backup engine V2 is disabled (config backup_v2.enabled=false).');
        }

        $session = BackupSession::findOrFail($this->backupSessionId);
        $site = $session->site;
        if (! $site instanceof Site) {
            throw new RuntimeException("BackupSession {$session->id} has no site.");
        }

        $logger = (new BackupLogger)->forSession('backup', $session->id, $session->site_id);

        $s3 = S3ClientFactory::lab(); // TODO(prod): S3ClientFactory::forDestination($site->...)
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
