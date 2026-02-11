<?php

namespace App\Services;

use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Support\Collection;

class WidgetService
{
    protected const WIDGET_TYPES = [
        'stats_overview',
        'alert_center',
        'quick_actions',
        'sites_needing_attention',
        'recent_activity',
        'health_distribution',
        'backup_status',
        'traffic_analytics',
    ];

    public function createDefaultWidgets(int $userId): void
    {
        $defaultLayout = [
            // Row 1: Stats Overview (full width)
            ['type' => 'stats_overview', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 2],

            // Row 2: Alert Center + Quick Actions
            ['type' => 'alert_center', 'x' => 0, 'y' => 2, 'w' => 8, 'h' => 3],
            ['type' => 'quick_actions', 'x' => 8, 'y' => 2, 'w' => 4, 'h' => 3],

            // Row 3: Sites Needing Attention + Recent Activity
            ['type' => 'sites_needing_attention', 'x' => 0, 'y' => 5, 'w' => 6, 'h' => 4],
            ['type' => 'recent_activity', 'x' => 6, 'y' => 5, 'w' => 6, 'h' => 4],

            // Row 4: Health Distribution + Backup Status
            ['type' => 'health_distribution', 'x' => 0, 'y' => 9, 'w' => 6, 'h' => 3],
            ['type' => 'backup_status', 'x' => 6, 'y' => 9, 'w' => 6, 'h' => 3],
        ];

        foreach ($defaultLayout as $index => $widget) {
            DashboardWidget::create([
                'user_id' => $userId,
                'widget_type' => $widget['type'],
                'config' => $this->getDefaultConfig($widget['type']),
                'grid_x' => $widget['x'],
                'grid_y' => $widget['y'],
                'grid_w' => $widget['w'],
                'grid_h' => $widget['h'],
                'is_visible' => true,
                'sort_order' => $index,
            ]);
        }
    }

    public function addWidget(int $userId, string $type, ?array $position = null): DashboardWidget
    {
        if (!in_array($type, self::WIDGET_TYPES)) {
            throw new \InvalidArgumentException("Invalid widget type: {$type}");
        }

        // Check if widget already exists for this user
        if (DashboardWidget::where('user_id', $userId)->where('widget_type', $type)->exists()) {
            throw new \RuntimeException("Widget of type {$type} already exists for this user");
        }

        $maxSortOrder = DashboardWidget::where('user_id', $userId)->max('sort_order') ?? -1;

        return DashboardWidget::create([
            'user_id' => $userId,
            'widget_type' => $type,
            'config' => $this->getDefaultConfig($type),
            'grid_x' => $position['x'] ?? 0,
            'grid_y' => $position['y'] ?? 0,
            'grid_w' => $position['w'] ?? 6,
            'grid_h' => $position['h'] ?? 3,
            'is_visible' => true,
            'sort_order' => $maxSortOrder + 1,
        ]);
    }

    public function getDefaultConfig(string $type): array
    {
        return match ($type) {
            'stats_overview' => [
                'metrics' => ['total_sites', 'sites_up', 'sites_down', 'total_clients', 'avg_uptime', 'avg_response_time']
            ],
            'alert_center' => [
                'severity_filter' => ['critical', 'warning'],
                'limit' => 10
            ],
            'quick_actions' => [
                'actions' => ['add_site', 'bulk_sync', 'run_backups', 'generate_report', 'check_uptime', 'view_analytics']
            ],
            'sites_needing_attention' => [
                'limit' => 10,
                'min_health_score' => 70
            ],
            'recent_activity' => [
                'limit' => 20,
                'activity_types' => ['all']
            ],
            'health_distribution' => [],
            'backup_status' => [],
            'traffic_analytics' => [
                'period' => '7d'
            ],
            default => []
        };
    }

    public function getUserWidgets(int $userId): Collection
    {
        return DashboardWidget::forUser($userId)->get();
    }

    public function getVisibleWidgets(int $userId): Collection
    {
        return DashboardWidget::visibleForUser($userId)->get();
    }

    public function updateLayout(int $userId, array $layout): void
    {
        foreach ($layout as $item) {
            if (!isset($item['id'])) {
                continue;
            }

            DashboardWidget::where('user_id', $userId)
                ->where('id', $item['id'])
                ->update([
                    'grid_x' => $item['x'] ?? 0,
                    'grid_y' => $item['y'] ?? 0,
                    'grid_w' => $item['w'] ?? 4,
                    'grid_h' => $item['h'] ?? 2,
                ]);
        }
    }

    public function removeWidget(int $userId, int $widgetId): void
    {
        DashboardWidget::where('user_id', $userId)
            ->where('id', $widgetId)
            ->delete();
    }

    public function resetToDefaults(int $userId): void
    {
        DashboardWidget::where('user_id', $userId)->delete();
        $this->createDefaultWidgets($userId);
    }

    public function toggleVisibility(int $userId, int $widgetId): void
    {
        $widget = DashboardWidget::where('user_id', $userId)
            ->where('id', $widgetId)
            ->firstOrFail();

        $widget->update([
            'is_visible' => !$widget->is_visible
        ]);
    }

    public function getAvailableWidgetTypes(int $userId): array
    {
        $existingTypes = DashboardWidget::where('user_id', $userId)
            ->pluck('widget_type')
            ->toArray();

        return array_values(array_diff(self::WIDGET_TYPES, $existingTypes));
    }
}
