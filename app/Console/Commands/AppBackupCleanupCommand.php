<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AppBackup\AppBackupService;
use Illuminate\Console\Command;

class AppBackupCleanupCommand extends Command
{
    protected $signature = 'app:backup-cleanup';

    protected $description = 'Apply retention policy and clean up expired application backups';

    public function handle(AppBackupService $service): int
    {
        $this->info('Applying retention policy...');
        $service->applyRetention();

        $this->info('Cleaning up expired backups...');
        $service->cleanupExpired();

        $this->info('Cleanup completed.');

        return self::SUCCESS;
    }
}
