<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Support\BackupV2Gate;
use App\Backup\V2\Verification\DeepVerifyService;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Console\Command;

/**
 * Scheduled SAMPLED deep-verify for V2 backups (App\Backup\V2\Verification\DeepVerifyService):
 * downloads a sample of a completed backup's objects, opens the archives, parses
 * the DB SQL and re-hashes the bytes, recording a `deep` backup_verification.
 *
 * INERT without config('backup_v2.enabled') — with the default flag off the command
 * reports that V2 is disabled and does nothing (no storage reads, no DB writes), so it
 * is safe to register/schedule in production. The S3 client is resolved per session
 * from the site's own StorageDestination, exactly as RunBackupSessionJob does.
 */
class DeepVerifyCommand extends Command
{
    protected $signature = 'backup:v2-deep-verify '
        .'{--session= : Restrict to a single backup_session id} '
        .'{--site= : Restrict to a single site id} '
        .'{--sample=4 : Objects to fully download+open per backup (0 = all)} '
        .'{--client= : Override client_id in the object prefix (lab/testing)} '
        .'{--prefix-template= : Override the object-prefix template (lab/testing)} '
        .'{--json : Emit a machine-readable JSON report}';

    protected $description = 'Sampled deep-verify of V2 backups (opens archives + parses DB + re-hashes a sample).';

    public function handle(): int
    {
        if (! (bool) config('backup_v2.enabled', false)) {
            $this->warn('backup_v2.enabled is false — deep-verify is inert (nothing verified).');

            return self::SUCCESS;
        }

        $sample = (int) $this->option('sample');
        $service = new DeepVerifyService;

        /** @var array<int, \App\Backup\V2\Storage\S3ClientFactory> $factories */
        $factories = [];

        $results = [];
        foreach ($this->sessions() as $session) {
            // Allowlist enforcement: only deep-verify sessions whose site is on the
            // config('backup_v2.site_ids') allowlist.
            if (! BackupV2Gate::siteAllowed((int) $session->site_id)) {
                $results[] = [
                    'session_id' => $session->id,
                    'status' => 'skipped',
                    'sample_size' => 0,
                    'error' => 'not_allowlisted',
                ];

                continue;
            }

            // Resolve the bucket the objects were actually written to. This used
            // to be S3ClientFactory::lab() for every session, so on production
            // the command looked in the lab MinIO, found nothing, and recorded a
            // FAILED verification against a perfectly sound backup — a report
            // that manufactures the corruption it claims to detect.
            $site = $session->site;
            $destination = $site instanceof Site ? StorageDestination::resolveForSite($site) : null;
            if ($destination === null) {
                $results[] = [
                    'session_id' => $session->id,
                    'status' => 'skipped',
                    'sample_size' => 0,
                    'error' => 'no_storage_destination',
                ];

                continue;
            }

            $factory = $factories[$destination->id] ??= S3ClientFactory::forDestination($destination);
            $s3 = $factory->client();
            $bucket = $factory->bucket();

            $layout = $this->layoutFor($session);
            $verification = $service->deepVerify($session, $s3, $bucket, $layout, $sample);
            $results[] = [
                'session_id' => $session->id,
                'status' => $verification->status,
                'sample_size' => $verification->sample_size,
                'error' => $verification->error,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($results === []) {
            $this->info('No completed V2 backups to deep-verify.');

            return self::SUCCESS;
        }
        $this->table(['Session', 'Status', 'Sample', 'Error'], array_map(
            static fn (array $r): array => [$r['session_id'], $r['status'], $r['sample_size'], $r['error'] ?? ''],
            $results,
        ));

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, BackupSession>
     */
    private function sessions(): iterable
    {
        $query = BackupSession::query()->where('state', BackupSessionState::Completed->value);
        if ($this->option('session') !== null) {
            $query->whereKey((int) $this->option('session'));
        }
        if ($this->option('site') !== null) {
            $query->where('site_id', (int) $this->option('site'));
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Where the backup's objects actually are.
     *
     * This used to derive the prefix itself, and got it wrong twice over:
     * `--client` defaulted to 1 regardless of the site's owner, and the backup
     * id was `$session->id` where the runner uses `backup_id ?? id`. It asks
     * SessionLayoutResolver now, like everything else — a verifier with its own
     * idea of where to look does not verify a backup, it verifies its own
     * arithmetic.
     *
     * `--client` and `--prefix-template` survive only as lab overrides for
     * objects written by a harness rather than by the runner.
     */
    private function layoutFor(BackupSession $session): ObjectLayout
    {
        $template = $this->option('prefix-template');
        $clientOption = $this->option('client');

        if ((is_string($template) && $template !== '') || ($clientOption !== null && $clientOption !== '')) {
            $clientId = (int) ($clientOption !== null && $clientOption !== ''
                ? $clientOption
                : ($session->site?->getAttribute('client_id') ?? 0));
            $backupId = (int) ($session->backup_id ?? $session->id);

            return is_string($template) && $template !== ''
                ? new ObjectLayout($clientId, (int) $session->site_id, $backupId, $template)
                : ObjectLayout::forBackup($clientId, (int) $session->site_id, $backupId);
        }

        return SessionLayoutResolver::for($session);
    }
}
