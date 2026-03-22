<?php

namespace App\Console\Commands;

use App\Jobs\CreateAppBackup;
use App\Models\AppBackupConfig;
use Illuminate\Console\Command;

class ScheduledAppBackupCommand extends Command
{
    protected $signature = 'app-backup:schedule-check';
    protected $description = 'Check and dispatch scheduled application backups';

    public function handle(): void
    {
        $config = AppBackupConfig::query()
            ->where('is_enabled', true)
            ->where('next_backup_at', '<=', now())
            ->first();

        if ($config) {
            CreateAppBackup::dispatch(
                $config->type,
                'scheduled',
                $config->storage_destination_id,
            );
            $config->update(['next_backup_at' => $config->calculateNextBackupAt()]);
        }
    }
}
