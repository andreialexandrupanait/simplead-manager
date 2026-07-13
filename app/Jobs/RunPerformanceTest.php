<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SsrfException;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\PageSpeedService;
use App\Services\Security\SsrfGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunPerformanceTest implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 900; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public int $tries = 2;

    public array $backoff = [60, 180];

    public function __construct(
        public PerformanceMonitor $monitor,
        public string $device = 'both'
    ) {
        $this->onQueue('performance');
    }

    public function uniqueId(): string
    {
        return 'perf-test-'.$this->monitor->id;
    }

    /**
     * P1-09: a hard failure (all tries exhausted) must never leave rows stuck in
     * 'running' — the performance UI polls those forever. A `kill -9` skips this
     * hook entirely, which is why the scheduled `performance:recover-stuck-tests`
     * sweeper is the belt-and-braces backstop.
     */
    public function failed(\Throwable $e): void
    {
        $this->failOrphanedRunningTests('Test run failed: '.$e->getMessage());
    }

    /**
     * Mark any lingering 'running' rows for this monitor as failed so the UI
     * stops polling and no duplicate stuck rows survive. (P1-09)
     */
    private function failOrphanedRunningTests(string $reason): void
    {
        PerformanceTest::withoutGlobalScope('non_competitor')
            ->where('performance_monitor_id', $this->monitor->id)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'error_message' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function handle(PageSpeedService $pageSpeed): void
    {
        // P1-09: a redelivered/retried run must not accumulate duplicate stuck
        // rows. ShouldBeUnique guarantees only one RunPerformanceTest per monitor
        // executes at a time, so any pre-existing 'running' row is necessarily
        // orphaned by a prior attempt that died without cleanup — fail it first.
        $this->failOrphanedRunningTests('Superseded by a new test run (orphaned running row).');

        /** @var Site $site */
        $site = $this->monitor->site;

        $devices = $this->device === 'both' ? ['mobile', 'desktop'] : [$this->device];
        $pages = $this->monitor->pages()->orderByDesc('is_primary')->orderBy('label')->get();

        if ($pages->isEmpty()) {
            // Backward compat: no pages configured, test site URL directly
            $this->runTestForUrl($pageSpeed, $site, $site->url, null, $devices);
        } else {
            $first = true;
            foreach ($pages as $page) {
                /** @var \App\Models\PerformancePage $page */
                if (! $first) {
                    sleep(2); // Rate-limit between API calls
                }
                $this->runTestForUrl($pageSpeed, $site, $page->url, $page->id, $devices);
                $first = false;
            }
        }

        $this->runCompetitorBenchmarks($pageSpeed, $site, $devices);

        $this->updateMonitorScores();
        $this->checkAlerts();
        $this->checkBudgets();
    }

    /**
     * P2-15: benchmark each configured competitor URL on the monitor's cadence so
     * the competitor comparison in the UI is populated with real data instead of
     * being a dead field. Results are stored as PerformanceTest rows flagged
     * is_competitor=true and keyed by competitor_url, which is exactly what
     * WithPerformanceCompetitors::competitorComparison() reads back.
     *
     * Competitor URLs are user-supplied, so each is SSRF-validated before it is
     * handed to PageSpeed (defence in depth — PageSpeed itself fetches server-side).
     *
     * @param  list<string>  $devices
     */
    private function runCompetitorBenchmarks(PageSpeedService $pageSpeed, Site $site, array $devices): void
    {
        $competitors = array_values(array_filter(
            $this->monitor->competitor_urls ?? [],
            fn ($url) => is_string($url) && $url !== '',
        ));

        if ($competitors === []) {
            return;
        }

        /** @var SsrfGuard $guard */
        $guard = app(SsrfGuard::class);

        foreach ($competitors as $competitorUrl) {
            try {
                $guard->assertPublicUrl($competitorUrl);
            } catch (SsrfException $e) {
                Log::warning("Skipping competitor benchmark for non-public URL {$competitorUrl}: {$e->getMessage()}");

                continue;
            }

            foreach ($devices as $device) {
                sleep(2); // Rate-limit between PageSpeed API calls

                $test = PerformanceTest::create([
                    'site_id' => $site->id,
                    'performance_monitor_id' => $this->monitor->id,
                    'device' => $device,
                    'url' => $competitorUrl,
                    'is_competitor' => true,
                    'competitor_url' => $competitorUrl,
                    'status' => 'running',
                    'tested_at' => now(),
                ]);

                try {
                    $results = $pageSpeed->analyze($competitorUrl, $device);

                    $test->update([
                        'status' => 'completed',
                        ...$results,
                    ]);
                } catch (\Exception $e) {
                    $test->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);

                    report($e);
                }
            }
        }
    }

    private function runTestForUrl(PageSpeedService $pageSpeed, Site $site, string $url, ?int $pageId, array $devices): void
    {
        foreach ($devices as $device) {
            $test = PerformanceTest::create([
                'site_id' => $site->id,
                'performance_monitor_id' => $this->monitor->id,
                'performance_page_id' => $pageId,
                'device' => $device,
                'url' => $url,
                'status' => 'running',
                'tested_at' => now(),
            ]);

            try {
                $results = $pageSpeed->analyze($url, $device);

                $test->update([
                    'status' => 'completed',
                    ...$results,
                ]);

                // Save desktop screenshot for site card thumbnail (primary page only)
                if ($device === 'desktop') {
                    if ($pageId === null || ($this->monitor->pages()->where('id', $pageId)->value('is_primary'))) {
                        $this->saveScreenshot($site, $test);
                    }
                }
            } catch (\Exception $e) {
                $test->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                report($e);
            }

        }
    }

    private function checkBudgets(): void
    {
        $budgets = $this->monitor->budgets;
        if (empty($budgets)) {
            return;
        }

        // Get latest completed mobile test for primary page
        /** @var PerformanceTest|null $latestTest */
        $latestTest = $this->monitor->tests()
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        if (! $latestTest) {
            return;
        }

        // Get previous test to compare
        /** @var PerformanceTest|null $previousTest */
        $previousTest = $this->monitor->tests()
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->where('id', '<', $latestTest->id)
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        $budgetMap = [
            'performance_score' => ['field' => 'performance_score', 'compare' => 'min'],
            'lcp' => ['field' => 'lcp', 'compare' => 'max'],
            'cls' => ['field' => 'cls', 'compare' => 'max'],
            'tbt' => ['field' => 'tbt', 'compare' => 'max'],
            'fcp' => ['field' => 'fcp', 'compare' => 'max'],
            'si' => ['field' => 'si', 'compare' => 'max'],
            'total_size_bytes' => ['field' => 'total_size_bytes', 'compare' => 'max'],
            'js_size' => ['field' => 'js_size', 'compare' => 'max'],
            'image_size' => ['field' => 'image_size', 'compare' => 'max'],
        ];

        $violations = [];
        $previousViolations = [];

        foreach ($budgets as $key => $budget) {
            if (! isset($budgetMap[$key]) || $budget === null || $budget === '') {
                continue;
            }

            $config = $budgetMap[$key];
            $actual = $latestTest->{$config['field']};
            if ($actual === null) {
                continue;
            }

            $budgetValue = (float) $budget;
            $exceeded = $config['compare'] === 'min'
                ? $actual < $budgetValue
                : $actual > $budgetValue;

            if ($exceeded) {
                $violations[$key] = [
                    'key' => $key,
                    'budget' => $budgetValue,
                    'actual' => $actual,
                ];
            }

            // Check if previous test also violated
            if ($previousTest) {
                $prevActual = $previousTest->{$config['field']};
                if ($prevActual !== null) {
                    $prevExceeded = $config['compare'] === 'min'
                        ? $prevActual < $budgetValue
                        : $prevActual > $budgetValue;
                    if ($prevExceeded) {
                        $previousViolations[$key] = true;
                    }
                }
            }
        }

        // Only notify on newly exceeded budgets
        $newViolations = array_diff_key($violations, $previousViolations);

        if (! empty($newViolations)) {
            NotifyBudgetViolation::dispatch($this->monitor, $newViolations, $latestTest);
        }
    }

    private function saveScreenshot(Site $site, PerformanceTest $test): void
    {
        try {
            $dataUri = $test->screenshot_final;
            if (! $dataUri || ! str_contains($dataUri, 'base64,')) {
                return;
            }

            $base64 = explode('base64,', $dataUri, 2)[1];
            $imageData = base64_decode($base64);
            if (! $imageData) {
                return;
            }

            $src = @imagecreatefromstring($imageData);
            if (! $src) {
                return;
            }

            $origW = imagesx($src);
            $origH = imagesy($src);
            $newW = 800;
            $newH = (int) round($origH * ($newW / $origW));

            $dst = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

            ob_start();
            imagejpeg($dst, null, 80);
            $jpeg = ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            $path = "screenshots/{$site->id}.jpg";
            Storage::disk('public')->put($path, $jpeg);
            $site->update(['screenshot_path' => $path]);
        } catch (\Exception $e) {
            // Non-critical — fail silently
        }
    }

    private function updateMonitorScores(): void
    {
        $this->monitor->update([
            'previous_mobile_score' => $this->monitor->latest_mobile_score,
            'previous_desktop_score' => $this->monitor->latest_desktop_score,
        ]);

        // Scope to primary page (null page_id OR page.is_primary = true)
        /** @var PerformanceTest|null $latestMobile */
        $latestMobile = $this->monitor->tests()
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        /** @var PerformanceTest|null $latestDesktop */
        $latestDesktop = $this->monitor->tests()
            ->where('device', 'desktop')
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        // P2-16: honor the plan-configured interval instead of the coarse
        // daily/weekly bucket, which ignored interval_minutes entirely.
        $nextTestAt = $this->monitor->calculateNextTestAt();

        $this->monitor->update([
            'latest_mobile_score' => $latestMobile?->performance_score,
            'latest_desktop_score' => $latestDesktop?->performance_score,
            'last_tested_at' => now(),
            'next_test_at' => $nextTestAt,
        ]);
    }

    private function checkAlerts(): void
    {
        if (! $this->monitor->alert_on_score_drop) {
            return;
        }

        $threshold = $this->monitor->score_drop_threshold;

        /** @var Site $alertSite */
        $alertSite = $this->monitor->site;

        // Check mobile score drop
        if ($this->monitor->previous_mobile_score !== null && $this->monitor->latest_mobile_score !== null) {
            $drop = $this->monitor->previous_mobile_score - $this->monitor->latest_mobile_score;
            if ($drop >= $threshold) {
                NotifyPerformanceDrop::dispatch(
                    $this->monitor,
                    'mobile',
                    $this->monitor->previous_mobile_score,
                    $this->monitor->latest_mobile_score
                );
                ActivityLogger::performanceScoreDrop($alertSite, 'mobile', $this->monitor->previous_mobile_score, $this->monitor->latest_mobile_score);
            }
        }

        // Check desktop score drop
        if ($this->monitor->previous_desktop_score !== null && $this->monitor->latest_desktop_score !== null) {
            $drop = $this->monitor->previous_desktop_score - $this->monitor->latest_desktop_score;
            if ($drop >= $threshold) {
                NotifyPerformanceDrop::dispatch(
                    $this->monitor,
                    'desktop',
                    $this->monitor->previous_desktop_score,
                    $this->monitor->latest_desktop_score
                );
                ActivityLogger::performanceScoreDrop($alertSite, 'desktop', $this->monitor->previous_desktop_score, $this->monitor->latest_desktop_score);
            }
        }
    }
}
