<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\DashboardService;

class HealthDistributionWidget extends BaseWidget
{
    protected DashboardService $dashboardService;

    public function boot(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getWidgetType(): string
    {
        return 'health_distribution';
    }

    public function getDefaultConfig(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Health Distribution';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-chart-pie';
    }

    public function getDescription(): string
    {
        return 'Site health breakdown';
    }

    public function getWidgetData(): array
    {
        return $this->dashboardService->getHealthDistribution();
    }

    public function getMinWidth(): int
    {
        return 4;
    }

    public function getMinHeight(): int
    {
        return 3;
    }
}
