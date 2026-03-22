<?php

namespace App\Livewire\Performance;

use App\Jobs\RunPerformanceTest;
use App\Livewire\Traits\WithSorting;
use App\Models\PerformanceMonitor;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PerformanceOverview extends Component
{
    use WithSorting;

    public string $search = '';

    public function mount(): void
    {
        // Override trait defaults for this component
        if (! request()->has('sortBy')) {
            $this->sortBy = 'manual';
        }
        if (! request()->has('sortDir')) {
            $this->sortDir = 'asc';
        }
    }

    public function updatingSearch(): void
    {
        // Reset when search changes
    }

    #[Computed]
    public function stats(): array
    {
        // Single query to get all active monitors with their latest tests
        $monitors = PerformanceMonitor::where('is_active', true)
            ->whereHas('site')
            ->with('latestMobileTest')
            ->get();

        $total = $monitors->count();

        $avgMobile = $monitors->whereNotNull('latest_mobile_score')->avg('latest_mobile_score');
        $avgDesktop = $monitors->whereNotNull('latest_desktop_score')->avg('latest_desktop_score');

        $poorCount = $monitors->filter(fn ($m) => ($m->latest_mobile_score !== null && $m->latest_mobile_score < 50) ||
            ($m->latest_desktop_score !== null && $m->latest_desktop_score < 50)
        )->count();

        $budgetViolations = $monitors
            ->filter(function ($monitor) {
                $budgets = $monitor->budgets;
                if (empty($budgets)) {
                    return false;
                }
                $test = $monitor->latestMobileTest;
                if (! $test) {
                    return false;
                }
                $minBudgets = ['performance_score'];
                foreach ($budgets as $key => $budget) {
                    if ($budget === null || $budget === '') {
                        continue;
                    }
                    $actual = $test->$key;
                    if ($actual === null) {
                        continue;
                    }
                    $isMin = in_array($key, $minBudgets);
                    $exceeded = $isMin ? $actual < (float) $budget : $actual > (float) $budget;
                    if ($exceeded) {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        return [
            'total' => $total,
            'avg_mobile' => $avgMobile ? round($avgMobile) : null,
            'avg_desktop' => $avgDesktop ? round($avgDesktop) : null,
            'poor_count' => $poorCount,
            'budget_violations' => $budgetViolations,
        ];
    }

    public function testAllSites(): void
    {
        $monitors = PerformanceMonitor::where('is_active', true)
            ->whereHas('site')
            ->with('site')
            ->get();

        $queued = 0;
        foreach ($monitors as $monitor) {
            if (! $monitor->site || ! $monitor->site->is_connected) {
                continue;
            }
            RunPerformanceTest::dispatch($monitor, 'both');
            $queued++;
        }

        session()->flash('perf-success', "Queued performance tests for {$queued} site(s).");
    }

    public function render()
    {
        $query = PerformanceMonitor::query()
            ->where('is_active', true)
            ->whereHas('site')
            ->with(['site', 'latestMobileTest', 'latestDesktopTest'])
            ->when($this->search, function ($q) {
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('domain', 'ilike', "%{$this->search}%"));
            });

        // Apply sorting
        if ($this->sortBy === 'manual') {
            $monitors = $query
                ->join('sites', 'performance_monitors.site_id', '=', 'sites.id')
                ->orderBy('sites.sort_order', 'asc')
                ->select('performance_monitors.*')
                ->get();
        } else {
            $sortColumn = match ($this->sortBy) {
                'mobile_score' => 'latest_mobile_score',
                'desktop_score' => 'latest_desktop_score',
                default => 'latest_mobile_score',
            };

            $monitors = $query->orderBy($sortColumn, $this->sortDir)->get();
        }

        // For LCP and trend sorting, sort in-memory since they're on related models
        if ($this->sortBy === 'lcp') {
            $monitors = $monitors->sortBy(function ($m) {
                return $m->latestMobileTest?->lcp ?? PHP_FLOAT_MAX;
            }, SORT_REGULAR, $this->sortDir === 'desc');
        }

        if ($this->sortBy === 'trend') {
            $monitors = $monitors->sortBy(function ($m) {
                $current = $m->latest_mobile_score;
                $previous = $m->previous_mobile_score;
                if ($current === null || $previous === null) {
                    return 0;
                }

                return $current - $previous;
            }, SORT_REGULAR, $this->sortDir === 'desc');
        }

        return view('livewire.performance.performance-overview', [
            'monitors' => $monitors,
        ])->layout('components.layouts.app', ['title' => 'Performance']);
    }
}
