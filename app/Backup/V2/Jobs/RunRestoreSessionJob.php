<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Restore\RestoreRunner;
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
 * Queued entry point that runs (or resumes) one RestoreSession through RestoreRunner.
 *
 * SAFETY: inert in production. It refuses to run unless BOTH config('backup_v2.enabled') AND
 * config('backup_v2.restore_enabled') are true — both default FALSE. Nothing dispatches this until
 * the owner explicitly turns V2 restore on for a whitelisted site.
 *
 * PRODUCTION wiring (TODO): resolve the S3 destination + per-site plugin credentials from the site's
 * StorageDestination instead of the lab factories (mirrors RunBackupSessionJob's remaining seam).
 * The pre-restore safety backup + health-check closures are wired by the dispatcher.
 */
final class RunRestoreSessionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $restoreSessionId) {}

    public function uniqueId(): string
    {
        return 'restore-v2-session-'.$this->restoreSessionId;
    }

    public function handle(): void
    {
        if (! (bool) config('backup_v2.enabled', false) || ! (bool) config('backup_v2.restore_enabled', false)) {
            throw new RuntimeException('Backup engine V2 restore is disabled (backup_v2.enabled / restore_enabled).');
        }

        $session = RestoreSession::findOrFail($this->restoreSessionId);
        $site = $session->site;
        if (! $site instanceof Site) {
            throw new RuntimeException("RestoreSession {$session->id} has no site.");
        }

        $logger = (new BackupLogger)->forSession('restore', $session->id, $session->site_id);

        $s3 = S3ClientFactory::lab(); // TODO(prod): S3ClientFactory::forDestination($site->...)
        $client = SimpleadBackupClient::forSite($site, $logger);

        $layoutFor = static fn (BackupSession $b): ObjectLayout => ObjectLayout::forBackup(
            clientId: $b->site?->getAttribute('client_id') ?? 0,
            siteId: $b->site_id,
            backupId: $b->backup_id ?? $b->id,
        );

        $reader = new S3ManifestReader($s3->client(), $s3->bucket(), $layoutFor);

        (new RestoreRunner(
            session: $session,
            client: $client,
            s3: $s3->client(),
            bucket: $s3->bucket(),
            resolver: new ChainResolver,
            reader: $reader,
            layoutFor: $layoutFor,
            preRestoreBackup: null, // TODO(prod): dispatch a safety BackupRunner and return its id
            healthCheck: null,      // TODO(prod): probe site HTTP + expected DB tables/rows
            logger: $logger,
        ))->run();
    }
}
