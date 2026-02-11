<?php

namespace App\Livewire\Dashboard\Widgets;

class QuickActionsWidget extends BaseWidget
{
    public function getWidgetType(): string
    {
        return 'quick_actions';
    }

    public function getDefaultConfig(): array
    {
        return [
            'actions' => ['add_site', 'bulk_sync', 'run_backups', 'generate_report', 'check_uptime', 'view_analytics']
        ];
    }

    public function getTitle(): string
    {
        return 'Quick Actions';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-bolt';
    }

    public function getDescription(): string
    {
        return 'Fast access to common operations';
    }

    public function getWidgetData(): array
    {
        return [
            'actions' => $this->config['actions'] ?? []
        ];
    }

    public function getMinWidth(): int
    {
        return 4;
    }

    public function getMinHeight(): int
    {
        return 3;
    }

    public function performAction(string $action)
    {
        return match ($action) {
            'add_site' => redirect()->route('sites.create'),
            'view_analytics' => redirect()->route('dashboard'),
            default => $this->dispatch('notify', [
                'type' => 'info',
                'message' => 'Action coming soon!'
            ])
        };
    }
}
