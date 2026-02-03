<?php

namespace App\Livewire\Performance;

use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PerformanceOverview extends Component
{
    public string $search = '';
    public string $sortBy = 'mobile_score';
    public string $sortDir = 'desc';

    public function updatingSearch(): void
    {
        // Reset when search changes
    }

    #[Computed]
    public function stats(): array
    {
        $monitors = PerformanceMonitor::where('is_active', true);
        $total = $monitors->count();

        $avgMobile = PerformanceMonitor::where('is_active', true)
            ->whereNotNull('latest_mobile_score')
            ->avg('latest_mobile_score');

        $avgDesktop = PerformanceMonitor::where('is_active', true)
            ->whereNotNull('latest_desktop_score')
            ->avg('latest_desktop_score');

        $poorCount = PerformanceMonitor::where('is_active', true)
            ->where(function ($q) {
                $q->where('latest_mobile_score', '<', 50)
                    ->orWhere('latest_desktop_score', '<', 50);
            })
            ->count();

        $budgetViolations = PerformanceMonitor::where('is_active', true)
            ->whereNotNull('budgets')
            ->get()
            ->filter(function ($monitor) {
                $budgets = $monitor->budgets;
                if (empty($budgets)) {
                    return false;
                }
                $test = $monitor->latestMobileTest;
                if (!$test) {
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
            ->with('site')
            ->get();

        $queued = 0;
        foreach ($monitors as $monitor) {
            if (!$monitor->site || !$monitor->site->is_connected) {
                continue;
            }
            RunPerformanceTest::dispatch($monitor, 'both');
            $queued++;
        }

        session()->flash('perf-success', "Queued performance tests for {$queued} site(s).");
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function render()
    {
        $query = PerformanceMonitor::query()
            ->where('is_active', true)
            ->with(['site', 'latestMobileTest', 'latestDesktopTest'])
            ->when($this->search, function ($q) {
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'like', "%{$this->search}%")
                    ->orWhere('domain', 'like', "%{$this->search}%"));
            });

        // Apply sorting
        $sortColumn = match ($this->sortBy) {
            'mobile_score' => 'latest_mobile_score',
            'desktop_score' => 'latest_desktop_score',
            default => 'latest_mobile_score',
        };

        $monitors = $query->orderBy($sortColumn, $this->sortDir)->get();

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
