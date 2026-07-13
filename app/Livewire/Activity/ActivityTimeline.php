<?php

declare(strict_types=1);

namespace App\Livewire\Activity;

use App\Enums\ActivityType;
use App\Livewire\Traits\WithVisibleSites;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class ActivityTimeline extends Component
{
    use WithPagination, WithVisibleSites;

    public string $filter = 'all';

    public string $search = '';

    public string $severity = 'all';

    public string $dateRange = 'week';

    /**
     * Restrict the timeline to activity the acting user may see: events on a
     * visible site (owned or via an assigned client), plus their own app-level
     * events (no site attached). Admins are unrestricted. Without this, every
     * tenant's activity leaked onto the global timeline (P1-02).
     */
    private function applyVisibility(Builder $query): Builder
    {
        $ids = $this->visibleSiteIds();

        if ($ids === null) {
            return $query;
        }

        $userId = auth()->id();

        return $query->where(function (Builder $q) use ($ids, $userId) {
            $q->whereIn('site_id', $ids)
                ->orWhere(fn (Builder $own) => $own->whereNull('site_id')->where('user_id', $userId));
        });
    }

    #[Computed]
    public function stats(): array
    {
        $baseQuery = $this->applyVisibility(ActivityLog::query())
            ->where('created_at', '>=', $this->dateStart());

        return [
            'total' => (clone $baseQuery)->count(),
            'critical' => (clone $baseQuery)->where('severity', 'critical')->count(),
            'warning' => (clone $baseQuery)->where('severity', 'warning')->count(),
            'success' => (clone $baseQuery)->where('severity', 'success')->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return ActivityType::filterOptions();
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

    /**
     * Lower bound for the "since" cursor. Pinned to UTC so the comparison is
     * deterministic regardless of the app/container timezone: activity
     * timestamps are stored as UTC wall-clock, and binding a Carbon carrying a
     * non-UTC offset would shift the window by that offset and silently
     * include/exclude events at the boundary (P3-28).
     */
    private function dateStart(): \Carbon\Carbon
    {
        $now = now('UTC');

        return match ($this->dateRange) {
            'today' => $now->startOfDay(),
            'week' => $now->subWeek(),
            'month' => $now->subMonth(),
            'quarter' => $now->subQuarter(),
            default => $now->subWeek(),
        };
    }

    public function render()
    {
        $events = $this->applyVisibility(ActivityLog::query())
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
