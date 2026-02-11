<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Services\DashboardService;

class AlertCenterWidget extends BaseWidget
{
    protected DashboardService $dashboardService;

    public function boot(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getWidgetType(): string
    {
        return 'alert_center';
    }

    public function getDefaultConfig(): array
    {
        return [
            'severity_filter' => ['critical', 'warning'],
            'limit' => 10
        ];
    }

    public function getTitle(): string
    {
        return 'Alert Center';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-bell-alert';
    }

    public function getDescription(): string
    {
        return 'Prioritized alerts with actions';
    }

    public function getWidgetData(): array
    {
        $alerts = $this->dashboardService->getAlerts();

        // Apply severity filter
        $severityFilter = $this->config['severity_filter'] ?? ['critical', 'warning'];
        if (!empty($severityFilter)) {
            $alerts = array_filter($alerts, function ($alert) use ($severityFilter) {
                return in_array($alert['severity'], $severityFilter);
            });
        }

        // Apply limit
        $limit = $this->config['limit'] ?? 10;
        $alerts = array_slice($alerts, 0, $limit);

        return [
            'alerts' => $alerts,
            'total_count' => count($alerts),
            'critical_count' => count(array_filter($alerts, fn($a) => $a['severity'] === 'critical')),
            'warning_count' => count(array_filter($alerts, fn($a) => $a['severity'] === 'warning')),
        ];
    }

    public function getMinWidth(): int
    {
        return 6;
    }

    public function getMinHeight(): int
    {
        return 3;
    }

    public function viewAlert(string $url)
    {
        return redirect($url);
    }
}
