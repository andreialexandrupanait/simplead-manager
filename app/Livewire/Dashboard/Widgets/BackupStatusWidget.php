<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\DashboardService;

class BackupStatusWidget extends BaseWidget
{
    protected DashboardService $dashboardService;

    public function boot(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getWidgetType(): string
    {
        return 'backup_status';
    }

    public function getDefaultConfig(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Backup Status';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-archive-box';
    }

    public function getDescription(): string
    {
        return 'Backup coverage metrics';
    }

    public function getWidgetData(): array
    {
        return $this->dashboardService->getBackupStatus();
    }

    public function getMinWidth(): int
    {
        return 6;
    }

    public function getMinHeight(): int
    {
        return 3;
    }
}
