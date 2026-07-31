<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Support\BackupV2Gate;
use App\Enums\BackupEngine;
use App\Models\BackupConfig;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Move one site between backup engines.
 *
 * The roster is a column rather than an environment variable so a site can be
 * moved without a redeploy, and so the decision is queryable and auditable
 * rather than living in a container's environment. This is the operator tool for
 * managing it during the migration, and it goes when the old engine does.
 *
 * It refuses to pretend: setting the column for a site the gate does not permit
 * changes nothing at dispatch time, and saying so at the moment of the change is
 * far better than leaving someone to discover it from a backup that ran on the
 * engine they thought they had left.
 */
class SetBackupEngineCommand extends Command
{
    protected $signature = 'backup:set-engine {site : Site id or domain} {engine : v1 or v2}';

    protected $description = 'Choose which backup engine runs a site';

    public function handle(): int
    {
        $engine = BackupEngine::tryFrom((string) $this->argument('engine'));
        if ($engine === null) {
            $this->error('The engine must be v1 or v2.');

            return self::FAILURE;
        }

        $site = $this->resolveSite((string) $this->argument('site'));
        if (! $site instanceof Site) {
            $this->error('No such site.');

            return self::FAILURE;
        }

        $config = BackupConfig::query()->where('site_id', $site->id)->first();
        if (! $config instanceof BackupConfig) {
            $this->error("Site {$site->id} has no backup configuration to move.");

            return self::FAILURE;
        }

        $was = $config->backup_engine ?? BackupEngine::V1;
        $config->update(['backup_engine' => $engine]);

        $this->info("{$site->domain}: {$was->value} → {$engine->value}");

        // The gate has the last word, and a site that is not on the allowlist
        // will keep running the old engine no matter what this column says.
        // Better to hear that now than to discover it from tonight's backup.
        $effective = BackupV2Gate::engineFor($site->fresh(), $config->fresh());
        if ($effective !== $engine) {
            $this->warn(sprintf(
                'This site will still run %s: the engine gate does not permit %s for it. '
                .'Check BACKUP_ENGINE_V2_ENABLED and BACKUP_ENGINE_V2_SITE_IDS.',
                $effective->value,
                $engine->value,
            ));
        }

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
