<?php

declare(strict_types=1);

namespace App\Livewire\Activity;

use App\Models\ActivityLog;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class ActivityTimeline extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public string $search = '';

    public string $severity = 'all';

    public string $dateRange = 'week';

    private const TYPES = [
        'all' => 'All',
        'uptime' => 'Uptime',
        'backup' => 'Backups',
        'update' => 'Updates',
        'plugin' => 'Plugins',
        'security' => 'Security',
        'auth' => 'Authentication',
        'performance' => 'Performance',
        'report' => 'Reports',
        'app_backup' => 'App Backup',
        'retention' => 'Cleanup',
    ];

    #[Computed]
    public function stats(): array
    {
        $baseQuery = ActivityLog::query()
            ->where('created_at', '>=', $this->dateStart());

        return [
            'total' => (clone $baseQuery)->count(),
            'critical' => (clone $baseQuery)->where('severity', 'critical')->count(),
            'warning' => (clone $baseQuery)->where('severity', 'warning')->count(),
            'success' => (clone $baseQuery)->where('severity', 'success')->count(),
        ];
    }

    #[Computed]
    public function typeOptions(): array
    {
        return self::TYPES;
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedDateRange(): void
    {
        $this->resetPage();
        unset($this->stats);
    }

    private function dateStart(): \Carbon\Carbon
    {
        return match ($this->dateRange) {
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            default => now()->subWeek(),
        };
    }

    public function render()
    {
        $events = ActivityLog::query()
            ->with(['site', 'user'])
            ->where('created_at', '>=', $this->dateStart())
            ->when($this->filter !== 'all', fn ($q) => $q->where('type', $this->filter))
            ->when($this->severity !== 'all', fn ($q) => $q->where('severity', $this->severity))
            ->when($this->search, function ($q) {
                $search = '%'.str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search).'%';
                $q->where(function ($sq) use ($search) {
                    $sq->where('title', 'ilike', $search)
                        ->orWhere('description', 'ilike', $search)
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'ilike', $search));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('livewire.activity.activity-timeline', [
            'events' => $events,
        ])->layout('components.layouts.app', ['title' => 'Activity']);
    }
}
