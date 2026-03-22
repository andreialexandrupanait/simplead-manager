<?php

namespace App\Console\Commands;

use App\Models\SecurityBannedIp;
use App\Services\SecurityActivityService;
use App\Services\SecurityCommandService;
use App\Services\SecuritySettingsService;
use Illuminate\Console\Command;

class SecurityMaintenanceCommand extends Command
{
    protected $signature = 'security:maintenance {task : The maintenance task to run (stale-commands|prune-logs|expired-bans|recalculate-scores)}';
    protected $description = 'Run security maintenance tasks';

    public function handle(
        SecurityCommandService $commandService,
        SecurityActivityService $activityService,
        SecuritySettingsService $settingsService,
    ): void {
        match ($this->argument('task')) {
            'stale-commands' => $commandService->cleanupStaleCommands(),
            'prune-logs' => $activityService->pruneOldLogs(90),
            'expired-bans' => SecurityBannedIp::whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->delete(),
            'recalculate-scores' => $settingsService->recalculateAllScores(),
            default => $this->error("Unknown task: {$this->argument('task')}"),
        };
    }
}
