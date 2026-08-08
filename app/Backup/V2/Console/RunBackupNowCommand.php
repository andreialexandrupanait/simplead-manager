<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Models\Site;
use App\Services\Backup\BackupLauncher;
use Illuminate\Console\Command;

/**
 * Start a backup for one site, right now.
 *
 * There was no way to do this from a shell at all: backups came from the
 * scheduler or from a button, so verifying a change meant either waiting for the
 * night or asking someone to click. That is a poor position to be in when the
 * thing you want to check is whether tonight's backup will work.
 *
 * Routing lives in BackupLauncher, with every other way of starting a backup.
 * This command used to carry its own copy, and the copy had drifted: its legacy
 * fallback passed a hard-coded 'full' and dropped --type on the floor.
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

        try {
            $backup = app(BackupLauncher::class)->launch($site, $requested, 'manual');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Queued %s backup for %s — backup #%d.',
            $backup->type,
            $site->domain,
            $backup->id,
        ));

        return self::SUCCESS;
    }

    /**
     * `Site::domain` is an accessor over `url` (getDomainAttribute), not a
     * column. Querying it threw "column domain does not exist", so this only
     * ever resolved a numeric id — which is not how anyone reaches for a site.
     * Reading `$site->domain` is fine and stays; only the WHERE was wrong.
     */
    private function resolveSite(string $needle): ?Site
    {
        if (ctype_digit($needle)) {
            return Site::find((int) $needle);
        }

        return Site::query()
            ->where('url', 'like', '%'.$needle.'%')
            ->orWhere('name', $needle)
            ->first();
    }
}
