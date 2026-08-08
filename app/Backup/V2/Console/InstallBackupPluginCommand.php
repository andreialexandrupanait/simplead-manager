<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Models\Site;
use App\Services\Backup\BackupPluginInstaller;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Put the V2 backup engine on one site, or on every site that runs it.
 *
 * The engine had no way onto a site except by hand through wp-admin, which is why it was live on
 * one site out of twenty-four and why every fix to it stopped at the edge of the fleet. It goes
 * through the connector, which fetches a signed, hashed package from this manager.
 *
 * `--all` deliberately means "every site already switched to V2", not every site: putting a backup
 * engine on a site that is not using it is a plugin nobody asked for and nobody is watching.
 */
class InstallBackupPluginCommand extends Command
{
    protected $signature = 'backup:install-plugin
                            {site? : Site id or domain}
                            {--all : Every site whose engine is V2}
                            {--dry-run : List what would be pushed, push nothing}';

    protected $description = 'Install or update the simplead-backup plugin on a site, via the connector';

    public function handle(BackupPluginInstaller $installer): int
    {
        $sites = $this->targets();

        if ($sites === null) {
            return self::FAILURE;
        }

        if ($sites->isEmpty()) {
            $this->warn('No sites matched.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($sites as $site) {
                $this->line("would push to {$site->domain}");
            }
            $this->info("{$sites->count()} site(s).");

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($sites as $site) {
            $result = $installer->install($site);

            if ($result['ok']) {
                $this->info("{$site->domain}: {$result['message']}");
            } else {
                $failed++;
                $this->error("{$site->domain}: {$result['message']}");
            }
        }

        $this->newLine();
        $this->info(sprintf('%d ok, %d failed.', $sites->count() - $failed, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>|null null when the arguments make no sense
     */
    private function targets(): ?Collection
    {
        $needle = (string) ($this->argument('site') ?? '');

        if ($needle === '' && ! $this->option('all')) {
            $this->error('Name a site, or pass --all.');

            return null;
        }

        if ($needle !== '') {
            // `domain` is an accessor over `url`, not a column — querying it throws on Postgres.
            $site = ctype_digit($needle)
                ? Site::find((int) $needle)
                : Site::query()->where('url', 'like', '%'.$needle.'%')->first();

            if (! $site instanceof Site) {
                $this->error('No such site.');

                return null;
            }

            return new Collection([$site]);
        }

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()
            ->whereHas('backupConfig')
            ->orderBy('id')
            ->get();

        return $sites;
    }
}
