<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\DashboardService;

class StatsOverviewWidget extends BaseWidget
{
    protected DashboardService $dashboardService;

    public function boot(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getWidgetType(): string
    {
        return 'stats_overview';
    }

    public function getDefaultConfig(): array
    {
        return [
            'metrics' => ['total_sites', 'sites_up', 'sites_down', 'total_clients', 'avg_uptime', 'avg_response_time']
        ];
    }

    public function getTitle(): string
    {
        return 'Stats Overview';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-chart-bar';
    }

    public function getDescription(): string
    {
        return 'Key metrics at a glance';
    }

    public function getWidgetData(): array
    {
        return $this->dashboardService->getStats();
    }

    public function getMinWidth(): int
    {
        return 6;
    }

    public function getMinHeight(): int
    {
        return 2;
    }
}
