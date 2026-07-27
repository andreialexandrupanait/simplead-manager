<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Verification\DeepVerifyService;
use Illuminate\Console\Command;

/**
 * Scheduled SAMPLED deep-verify for V2 backups (App\Backup\V2\Verification\DeepVerifyService):
 * downloads a sample of a completed backup's objects, opens the archives, parses
 * the DB SQL and re-hashes the bytes, recording a `deep` backup_verification.
 *
 * INERT without config('backup_v2.enabled') — with the default flag off the command
 * reports that V2 is disabled and does nothing (no storage reads, no DB writes), so it
 * is safe to register/schedule in production. The lab S3 (MinIO) client is used; the
 * production per-destination S3 resolution is the same TODO the rest of V2 shares
 * (S3ClientFactory).
 */
class DeepVerifyCommand extends Command
{
    protected $signature = 'backup:v2-deep-verify '
        .'{--session= : Restrict to a single backup_session id} '
        .'{--site= : Restrict to a single site id} '
        .'{--sample=4 : Objects to fully download+open per backup (0 = all)} '
        .'{--client=1 : client_id for the object-prefix template (lab)} '
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
        $factory = S3ClientFactory::lab();
        $s3 = $factory->client();
        $bucket = $factory->bucket();
        $service = new DeepVerifyService;

        $results = [];
        foreach ($this->sessions() as $session) {
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

    private function layoutFor(BackupSession $session): ObjectLayout
    {
        $template = $this->option('prefix-template');
        $client = (int) $this->option('client');

        if (is_string($template) && $template !== '') {
            return new ObjectLayout($client, (int) $session->site_id, (int) $session->id, $template);
        }

        return ObjectLayout::forBackup($client, (int) $session->site_id, (int) $session->id);
    }
}
