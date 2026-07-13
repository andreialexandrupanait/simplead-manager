<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\RunPerformanceTest;
use App\Livewire\Traits\WithPerformanceBudgets;
use App\Livewire\Traits\WithPerformanceCompetitors;
use App\Livewire\Traits\WithPerformanceSettings;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\PerformanceMonitor;
use App\Models\PerformancePage;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Models\UpdateLog;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SitePerformance extends Component
{
    use WithPerformanceBudgets, WithPerformanceCompetitors, WithPerformanceSettings, WithSiteAuthorization;

    public Site $site;

    public string $historyRange = '30d';

    public bool $isRunning = false;

    #[Locked]
    public ?int $trackingTestId = null;

    // Multi-page
    public ?int $selectedPageId = null;

    public string $newPageLabel = '';

    public string $newPageUrl = '';

    public bool $showAddPage = false;

    // Chart options
    public bool $showRollingAverage = true;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadSettings();

        // If there are running tests on page load, enable polling
        $monitor = $site->performanceMonitor;
        if ($monitor) {
            // P1-09: ignore rows older than the sweeper threshold so an orphaned
            // 'running' row (worker SIGKILL'd before failed() ran) can never make
            // the UI poll forever before the sweeper resolves it.
            $hasRunning = PerformanceTest::where('performance_monitor_id', $monitor->id)
                ->whereIn('status', ['running', 'pending'])
                ->where('created_at', '>=', now()->subMinutes(15))
                ->exists();
            if ($hasRunning) {
                $this->isRunning = true;
            }
        }
    }

    #[Computed]
    public function monitor(): ?PerformanceMonitor
    {
        return $this->site->performanceMonitor;
    }

    #[Computed]
    public function latestMobileTest(): ?PerformanceTest
    {
        return $this->monitor->latestMobileTest;
    }

    #[Computed]
    public function latestDesktopTest(): ?PerformanceTest
    {
        return $this->monitor->latestDesktopTest;
    }

    #[Computed]
    public function pages(): \Illuminate\Support\Collection
    {
        if (! $this->monitor) {
            return collect();
        }

        return $this->monitor->pages()->orderByDesc('is_primary')->orderBy('label')->get();
    }

    #[Computed]
    public function activeTest(): ?PerformanceTest
    {
        if (! $this->monitor) {
            return null;
        }

        $query = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->where('device', 'mobile')
            ->where('status', 'completed');

        if ($this->selectedPageId) {
            $query->where('performance_page_id', $this->selectedPageId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            });
        }

        return $query->latest('tested_at')->first();
    }

    #[Computed]
    public function activeDesktopTest(): ?PerformanceTest
    {
        if (! $this->monitor) {
            return null;
        }

        $query = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->where('device', 'desktop')
            ->where('status', 'completed');

        if ($this->selectedPageId) {
            $query->where('performance_page_id', $this->selectedPageId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            });
        }

        return $query->latest('tested_at')->first();
    }

    #[Computed]
    public function chartEventMarkers(): array
    {
        if (! $this->monitor) {
            return [];
        }

        $days = match ($this->historyRange) {
            '7d' => 7,
            '90d' => 90,
            '180d' => 180,
            default => 30,
        };

        return UpdateLog::where('site_id', $this->site->id)
            ->where('performed_at', '>=', now()->subDays($days))
            ->orderBy('performed_at')
            ->get()
            ->map(fn ($log) => [
                'date' => $log->performed_at->format('M j'),
                'label' => "{$log->type}: {$log->name} ({$log->from_version} → {$log->to_version})",
                'type' => $log->success ? 'success' : 'failure',
            ])
            ->toArray();
    }

    #[Computed]
    public function hasFieldData(): bool
    {
        $test = $this->latestMobileTest;
        if (! $test) {
            return false;
        }

        return $test->field_fcp !== null || $test->field_lcp !== null || $test->field_cls !== null;
    }

    #[Computed]
    public function activeTests(): \Illuminate\Support\Collection
    {
        if (! $this->monitor) {
            return collect();
        }

        // P1-09: bound by age so an orphaned 'running' row can't keep the poll
        // loop alive indefinitely (the sweeper marks it failed within 15 min).
        return PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->whereIn('status', ['running', 'pending'])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->get();
    }

    #[Computed]
    public function lastFinishedTest(): ?PerformanceTest
    {
        if (! $this->monitor) {
            return null;
        }

        return PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->whereIn('status', ['completed', 'failed'])
            ->where('tested_at', '>=', now()->subMinutes(5))
            ->latest('tested_at')
            ->first();
    }

    public function checkTestProgress(): void
    {
        unset($this->activeTests);
        unset($this->lastFinishedTest);
        unset($this->monitor);
        unset($this->latestMobileTest);
        unset($this->latestDesktopTest);
        unset($this->testHistory);
        unset($this->scoreHistory);
        unset($this->activeTest);
        unset($this->activeDesktopTest);
        unset($this->budgetViolations);

        // Only stop polling once the job has actually run and finished,
        // not when the job simply hasn't started yet (queue delay).
        if ($this->activeTests->isEmpty() && $this->lastFinishedTest) {
            $this->isRunning = false;
        }
    }

    #[Computed]
    public function trendSummary(): array
    {
        if (! $this->monitor) {
            return [];
        }

        $current = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->where('status', 'completed')
            ->whereNull('performance_page_id')
            ->where('tested_at', '>=', now()->subDays(30))
            ->get();

        $previous = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->where('status', 'completed')
            ->whereNull('performance_page_id')
            ->whereBetween('tested_at', [now()->subDays(60), now()->subDays(30)])
            ->get();

        if ($current->isEmpty()) {
            return [];
        }

        $currentMobile = (int) round($current->where('device', 'mobile')->avg('performance_score') ?? 0);
        $currentDesktop = (int) round($current->where('device', 'desktop')->avg('performance_score') ?? 0);
        $prevMobile = $previous->where('device', 'mobile')->avg('performance_score');
        $prevDesktop = $previous->where('device', 'desktop')->avg('performance_score');

        return [
            'mobile' => [
                'current' => $currentMobile,
                'change' => $prevMobile ? $currentMobile - (int) round($prevMobile) : null,
            ],
            'desktop' => [
                'current' => $currentDesktop,
                'change' => $prevDesktop ? $currentDesktop - (int) round($prevDesktop) : null,
            ],
        ];
    }

    #[Computed]
    public function scoreHistory(): array
    {
        if (! $this->monitor) {
            return ['labels' => [], 'datasets' => [], 'annotations' => []];
        }

        $days = match ($this->historyRange) {
            '7d' => 7,
            '90d' => 90,
            '180d' => 180,
            default => 30,
        };

        $query = PerformanceTest::where('performance_monitor_id', $this->monitor->id)
            ->where('status', 'completed')
            ->where('tested_at', '>=', now()->subDays($days));

        if ($this->selectedPageId) {
            $query->where('performance_page_id', $this->selectedPageId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            });
        }

        $tests = $query->orderBy('tested_at')->get();

        // P3-19: build a single date-keyed axis so the mobile and desktop series
        // stay aligned. Previously each device's scores were plucked into its own
        // positional array while the labels were a deduped union of both devices'
        // dates — so any day with only one device, or multiple tests on one day,
        // shifted the two lines out of alignment with each other and the x-axis.
        // Here every calendar day is one bucket; missing device-days are null-filled
        // (Chart.js skips null points) and same-day retests collapse to the latest.
        $byDate = [];
        foreach ($tests as $test) {
            $key = $test->tested_at->format('Y-m-d');
            if (! isset($byDate[$key])) {
                $byDate[$key] = ['label' => $test->tested_at->format('M j'), 'mobile' => null, 'desktop' => null];
            }
            if ($test->device === 'mobile') {
                $byDate[$key]['mobile'] = $test->performance_score;
            } elseif ($test->device === 'desktop') {
                $byDate[$key]['desktop'] = $test->performance_score;
            }
        }
        ksort($byDate);

        $labels = array_values(array_map(fn ($d) => $d['label'], $byDate));
        $mobileScores = array_values(array_map(fn ($d) => $d['mobile'], $byDate));
        $desktopScores = array_values(array_map(fn ($d) => $d['desktop'], $byDate));

        $datasets = [
            [
                'label' => 'Mobile',
                'data' => $mobileScores,
                'color' => '#8B5CF6',
            ],
            [
                'label' => 'Desktop',
                'data' => $desktopScores,
                'color' => '#3B82F6',
            ],
        ];

        // Add rolling averages if enabled and enough data
        if ($this->showRollingAverage) {
            $mobileAvg = $this->computeRollingAverage($mobileScores, 7);
            $desktopAvg = $this->computeRollingAverage($desktopScores, 7);

            if (count($mobileScores) >= 3) {
                $datasets[] = [
                    'label' => 'Mobile Avg',
                    'data' => $mobileAvg,
                    'color' => '#C4B5FD',
                    'borderDash' => [5, 5],
                    'pointRadius' => 0,
                ];
            }
            if (count($desktopScores) >= 3) {
                $datasets[] = [
                    'label' => 'Desktop Avg',
                    'data' => $desktopAvg,
                    'color' => '#93C5FD',
                    'borderDash' => [5, 5],
                    'pointRadius' => 0,
                ];
            }
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
            'annotations' => $this->chartEventMarkers,
        ];
    }

    #[Computed]
    public function testHistory()
    {
        if (! $this->monitor) {
            return collect();
        }

        $query = PerformanceTest::where('performance_monitor_id', $this->monitor->id);

        if ($this->selectedPageId) {
            $query->where('performance_page_id', $this->selectedPageId);
        }

        return $query->orderByDesc('tested_at')
            ->limit(20)
            ->get();
    }

    public function selectPage(?int $pageId): void
    {
        $this->selectedPageId = $pageId;
        unset($this->activeTest);
        unset($this->activeDesktopTest);
        unset($this->scoreHistory);
        unset($this->testHistory);
        unset($this->budgetViolations);
    }

    public function addPage(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->validate([
            'newPageLabel' => 'required|string|max:100',
            'newPageUrl' => 'required|url|max:500',
        ]);

        if (! $this->monitor) {
            return;
        }

        $isFirst = $this->monitor->pages()->count() === 0;

        PerformancePage::create([
            'performance_monitor_id' => $this->monitor->id,
            'label' => $this->newPageLabel,
            'url' => $this->newPageUrl,
            'is_primary' => $isFirst,
        ]);

        $this->newPageLabel = '';
        $this->newPageUrl = '';
        $this->showAddPage = false;
        unset($this->pages);
    }

    public function removePage(int $pageId): void
    {
        $this->authorizeSiteModification($this->site);

        $page = PerformancePage::where('performance_monitor_id', $this->monitor->id)->find($pageId);
        if (! $page) {
            return;
        }

        $wasPrimary = $page->is_primary;
        $page->delete();

        // Promote next page if deleted page was primary
        if ($wasPrimary) {
            $next = $this->monitor->pages()->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        if ($this->selectedPageId === $pageId) {
            $this->selectedPageId = null;
        }

        unset($this->pages);
        unset($this->activeTest);
        unset($this->activeDesktopTest);
    }

    public function setPrimaryPage(int $pageId): void
    {
        $this->authorizeSiteModification($this->site);

        if (! $this->monitor) {
            return;
        }

        // Remove primary from all
        $this->monitor->pages()->update(['is_primary' => false]);

        // Set new primary
        PerformancePage::where('performance_monitor_id', $this->monitor->id)
            ->where('id', $pageId)
            ->update(['is_primary' => true]);

        unset($this->pages);
    }

    public function runTest(): void
    {
        $this->authorizeSiteModification($this->site);

        $rateLimitKey = "performance-test:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 10, fn () => true, 3600)) {
            session()->flash('error', 'Too many performance test requests. Please wait before trying again.');

            return;
        }

        $monitor = $this->monitor;

        if (! $monitor) {
            $monitor = PerformanceMonitor::create([
                'site_id' => $this->site->id,
                'is_active' => true,
                'frequency' => $this->settingsFrequency,
                'test_time' => $this->settingsTestTime,
                'alert_on_score_drop' => $this->settingsAlertOnDrop,
                'score_drop_threshold' => $this->settingsThreshold,
            ]);
            unset($this->monitor);
        }

        $this->isRunning = true;
        RunPerformanceTest::dispatch($monitor, 'both');

        session()->flash('message', 'Performance test queued. Results will appear shortly.');
    }

    public function setHistoryRange(string $range): void
    {
        $this->historyRange = $range;
        unset($this->scoreHistory);
        unset($this->chartEventMarkers);
    }

    public function toggleActive(): void
    {
        $this->authorizeSiteModification($this->site);

        if (! $this->monitor) {
            return;
        }

        $this->monitor->update(['is_active' => ! $this->monitor->is_active]);
        unset($this->monitor);
    }

    private function computeRollingAverage(array $scores, int $window): array
    {
        $result = [];
        $count = count($scores);

        for ($i = 0; $i < $count; $i++) {
            $start = max(0, $i - $window + 1);
            $slice = array_slice($scores, $start, $i - $start + 1);
            $validScores = array_filter($slice, fn ($s) => $s !== null);
            $result[] = ! empty($validScores) ? round(array_sum($validScores) / count($validScores), 1) : null;
        }

        return $result;
    }

    public function render()
    {
        return view('livewire.sites.detail.site-performance')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Performance',
            ]);
    }
}
