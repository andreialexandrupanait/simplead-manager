<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Console\Command;

class BackupReleaseLock extends Command
{
    protected $signature = 'backup:release-lock
        {siteId : The site ID to release backup locks for}
        {--fail-in-progress : Also mark any in-progress backups for this site as failed}';

    protected $description = 'Release stuck unique job locks for backup jobs on a given site';

    public function handle(): int
    {
        $siteId = (int) $this->argument('siteId');

        $site = Site::find($siteId);
        if (!$site) {
            $this->error("Site #{$siteId} not found.");
            return self::FAILURE;
        }

        $this->info("Releasing backup locks for site #{$siteId} ({$site->domain})...");

        CreateBackup::releaseUniqueLock($siteId);
        CreateIncrementalBackup::releaseUniqueLock($siteId);

        $this->info('Unique job locks released.');

        if ($this->option('fail-in-progress')) {
            $count = Backup::where('site_id', $siteId)
                ->where('status', BackupStatus::InProgress)
                ->update([
                    'status' => BackupStatus::Failed,
                    'stage' => 'failed',
                    'progress_message' => 'Manually failed via backup:release-lock command',
                    'error_message' => 'Backup was manually marked as failed to release stuck locks.',
                    'completed_at' => now(),
                ]);

            $this->info("Marked {$count} in-progress backup(s) as failed.");
        }

        return self::SUCCESS;
    }
}
