<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\DashboardService;

class RecentActivityWidget extends BaseWidget
{
    protected DashboardService $dashboardService;

    public function boot(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getWidgetType(): string
    {
        return 'recent_activity';
    }

    public function getDefaultConfig(): array
    {
        return [
            'limit' => 20,
            'activity_types' => ['all']
        ];
    }

    public function getTitle(): string
    {
        return 'Recent Activity';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-clock';
    }

    public function getDescription(): string
    {
        return 'Timeline of recent events';
    }

    public function getWidgetData(): array
    {
        $limit = $this->config['limit'] ?? 20;
        $activities = $this->dashboardService->getRecentActivity($limit);

        return [
            'activities' => $activities,
            'count' => $activities->count(),
        ];
    }

    public function getMinWidth(): int
    {
        return 6;
    }

    public function getMinHeight(): int
    {
        return 4;
    }
}
