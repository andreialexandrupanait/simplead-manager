<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Chain\ChainPlanner;
use App\Backup\V2\Orchestration\SessionActions;
use App\Backup\V2\Support\BackupV2Gate;
use App\Enums\BackupEngine;
use App\Jobs\CreateBackup;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Start a backup for one site, right now.
 *
 * There was no way to do this from a shell at all: backups came from the
 * scheduler or from a button, so verifying a change meant either waiting for the
 * night or asking someone to click. That is a poor position to be in when the
 * thing you want to check is whether tonight's backup will work.
 *
 * Routes to whichever engine the site is actually on, for the same reason the
 * buttons do — a tool that could start the other engine would be a way to get
 * two of them working on one site.
 */
class RunBackupNowCommand extends Command
{
    protected $signature = 'backup:run-now {site : Site id or domain} {--type=full : full or incremental}';

    protected $description = 'Start a backup for one site immediately';

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if (! $site instanceof Site) {
            $this->error('No such site.');

            return self::FAILURE;
        }

        $requested = (string) $this->option('type');

        if (BackupV2Gate::engineFor($site) !== BackupEngine::V2) {
            $this->warn("{$site->domain} runs the legacy engine — dispatching that instead.");
            CreateBackup::dispatch($site, 'full', 'manual', $site->backupConfig?->storage_destination_id);

            return self::SUCCESS;
        }

        $plan = (new ChainPlanner)->planFor($site);
        $useChain = $requested === 'incremental' && $plan['type'] === 'incremental';

        $session = app(SessionActions::class)->startBackup(
            $site,
            $useChain ? 'incremental' : 'full',
            [
                'trigger' => 'manual',
                'full_base_id' => $useChain ? $plan['full_base_id'] : null,
                'chain_position' => $useChain ? $plan['chain_position'] : null,
            ],
        );

        $this->info(sprintf(
            'Queued %s backup for %s — session #%d, backup #%d.',
            $session->type,
            $site->domain,
            $session->id,
            (int) $session->backup_id,
        ));

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        if (ctype_digit($needle)) {
            return Site::find((int) $needle);
        }

        return Site::query()
            ->where('domain', $needle)
            ->orWhere('url', 'like', '%'.$needle.'%')
            ->first();
    }
}
