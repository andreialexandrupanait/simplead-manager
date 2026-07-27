<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Restore\PreRestoreSafetyBackup;
use App\Backup\V2\Restore\RestoreHealthCheck;
use App\Backup\V2\Restore\RestorePlan;
use App\Backup\V2\Restore\RestoreRunner;
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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Queued entry point that runs (or resumes) one RestoreSession through RestoreRunner.
 *
 * SAFETY: inert in production. handle() refuses to run unless BackupV2Gate::allowsSite() (enabled +
 * on the config('backup_v2.site_ids') allowlist) AND config('backup_v2.restore_enabled') are all true
 * — all default off/empty. Nothing dispatches this until the owner explicitly turns V2 restore on for
 * a whitelisted site.
 *
 * PRODUCTION wiring: S3 + per-site plugin credentials resolve from the site's real StorageDestination
 * (S3ClientFactory::forDestination / SimpleadBackupClient::forSite). The pre-restore safety backup
 * (PreRestoreSafetyBackup) and post-restore health-check (RestoreHealthCheck) closures are real — no
 * longer null — so post_restore_validation genuinely gates commit vs guaranteed rollback.
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
        $session = RestoreSession::findOrFail($this->restoreSessionId);
        $site = $session->site;
        if (! $site instanceof Site) {
            throw new RuntimeException("RestoreSession {$session->id} has no site.");
        }

        $logger = (new BackupLogger)->forSession('restore', $session->id, $session->site_id);

        // Hard guard: restore may only run when the engine is enabled AND the site is
        // allowlisted AND restore is explicitly enabled — all default off (zero prod
        // impact). BackupV2Gate::allowsSite covers enabled + allowlist.
        if (! BackupV2Gate::allowsSite((int) $session->site_id) || ! (bool) config('backup_v2.restore_enabled', false)) {
            $logger->warning('restore refused: site not enabled/allowlisted or restore disabled', [
                'enabled' => BackupV2Gate::enabled(),
                'restore_enabled' => (bool) config('backup_v2.restore_enabled', false),
                'site_id' => $session->site_id,
            ]);

            throw new RuntimeException(
                "Backup engine V2 restore refused site {$session->site_id}: not enabled/allowlisted or restore disabled."
            );
        }

        // Resolve the site's REAL S3 destination + per-site plugin credentials.
        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            throw new RuntimeException("No storage destination is configured for site {$session->site_id}.");
        }

        $s3 = S3ClientFactory::forDestination($destination);
        $client = SimpleadBackupClient::forSite($site, $logger);

        $layoutFor = static fn (BackupSession $b): ObjectLayout => ObjectLayout::forBackup(
            clientId: $b->site?->getAttribute('client_id') ?? 0,
            siteId: $b->site_id,
            backupId: $b->backup_id ?? $b->id,
        );

        $reader = new S3ManifestReader($s3->client(), $s3->bucket(), $layoutFor);

        // Real safety backup (returns the BackupSession id) — MANDATORY for MIRROR.
        $safetyBackup = new PreRestoreSafetyBackup($site, $client, $s3->client(), $s3->bucket(), $logger);
        // Real post-restore probe: site HTTP 2xx/3xx + core DB tables with rows.
        $healthCheck = new RestoreHealthCheck($site, app(HttpFactory::class), $logger);

        (new RestoreRunner(
            session: $session,
            client: $client,
            s3: $s3->client(),
            bucket: $s3->bucket(),
            resolver: new ChainResolver,
            reader: $reader,
            layoutFor: $layoutFor,
            preRestoreBackup: fn (RestoreSession $s): ?int => $safetyBackup($s),
            healthCheck: fn (RestoreSession $s, RestorePlan $p): bool => $healthCheck($s, $p),
            logger: $logger,
        ))->run();
    }
}
