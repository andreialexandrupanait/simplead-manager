<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Console\Command;

class BackupReleaseLock extends Command
{
    protected $signature = 'backup:release-lock
        {siteId : The site ID to release backup/restore locks for}
        {--fail-in-progress : Also mark any in-progress backups AND restores for this site as failed}';

    protected $description = 'Release stuck unique job locks (backup, restore, safe update) and the site operation lock for a given site';

    public function handle(): int
    {
        $siteId = (int) $this->argument('siteId');

        $site = Site::find($siteId);
        if (! $site) {
            $this->error("Site #{$siteId} not found.");

            return self::FAILURE;
        }

        $this->info("Releasing backup/restore locks for site #{$siteId} ({$site->domain})...");

        CreateBackup::releaseUniqueLock($siteId);
        CreateIncrementalBackup::releaseUniqueLock($siteId);

        // Restore unique locks are per-backup — release any with a live restore state.
        $restoreBackupIds = Backup::where('site_id', $siteId)
            ->whereIn('restore_status', [BackupStatus::Pending, BackupStatus::InProgress])
            ->pluck('id');

        foreach ($restoreBackupIds as $backupId) {
            RestoreBackup::releaseUniqueLock($backupId);
        }

        $holder = SiteOperationLock::current($siteId);
        SiteOperationLock::forceRelease($siteId);

        $this->info('Unique job locks released ('.$restoreBackupIds->count().' restore lock(s)).');
        $this->info($holder !== null
            ? "Site operation lock released (was held by: {$holder['operation']} {$holder['ref']} since {$holder['acquired_at']})."
            : 'Site operation lock was not held.');

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

            $restoreCount = Backup::where('site_id', $siteId)
                ->whereIn('restore_status', [BackupStatus::Pending, BackupStatus::InProgress])
                ->update([
                    'restore_status' => BackupStatus::Failed,
                    'restore_stage' => 'failed',
                    'restore_progress_message' => 'Manually failed via backup:release-lock command',
                    'restore_error_message' => 'Restore was manually marked as failed to release stuck locks. Verify the site state.',
                ]);

            $this->info("Marked {$count} in-progress backup(s) and {$restoreCount} restore(s) as failed.");
        }

        return self::SUCCESS;
    }
}
