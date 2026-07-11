<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SecurityBannedIp;
use App\Services\SecurityActivityService;
use App\Services\SecuritySettingsService;
use Illuminate\Console\Command;

class SecurityMaintenanceCommand extends Command
{
    protected $signature = 'security:maintenance {task : The maintenance task to run (prune-logs|expired-bans|recalculate-scores)}';

    protected $description = 'Run security maintenance tasks';

    public function handle(
        SecurityActivityService $activityService,
        SecuritySettingsService $settingsService,
    ): void {
        match ($this->argument('task')) {
            'prune-logs' => $activityService->pruneOldLogs(90),
            'expired-bans' => SecurityBannedIp::whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->delete(),
            'recalculate-scores' => $settingsService->recalculateAllScores(),
            default => $this->error("Unknown task: {$this->argument('task')}"),
        };
    }
}
