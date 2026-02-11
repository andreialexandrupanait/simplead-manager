<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\DashboardService;

class SitesNeedingAttentionWidget extends BaseWidget
{
    protected DashboardService $dashboardService;

    public function boot(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getWidgetType(): string
    {
        return 'sites_needing_attention';
    }

    public function getDefaultConfig(): array
    {
        return [
            'limit' => 10,
            'min_health_score' => 70
        ];
    }

    public function getTitle(): string
    {
        return 'Sites Needing Attention';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public function getDescription(): string
    {
        return 'Sites requiring action';
    }

    public function getWidgetData(): array
    {
        $limit = $this->config['limit'] ?? 10;
        $sites = $this->dashboardService->getSitesNeedingAttention($limit);

        return [
            'sites' => $sites,
            'count' => $sites->count(),
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
