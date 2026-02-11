<?php

namespace App\Jobs;

use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Jobs\NotifyBudgetViolation;
use App\Jobs\NotifyPerformanceDrop;
use App\Services\ActivityLogger;
use App\Services\MaintenanceService;
use App\Services\PageSpeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RunPerformanceTest implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;
    public array $backoff = [60, 180];

    public function __construct(
        public PerformanceMonitor $monitor,
        public string $device = 'both'
    ) {}

    public function uniqueId(): string
    {
        return 'perf-test-' . $this->monitor->id;
    }

    public function handle(PageSpeedService $pageSpeed): void
    {
        $site = $this->monitor->site;

        if (MaintenanceService::isSiteInMaintenance($site, 'performance')) {
            return;
        }

        $devices = $this->device === 'both' ? ['mobile', 'desktop'] : [$this->device];
        $pages = $this->monitor->pages()->orderByDesc('is_primary')->orderBy('label')->get();

        if ($pages->isEmpty()) {
            // Backward compat: no pages configured, test site URL directly
            $this->runTestForUrl($pageSpeed, $site, $site->url, null, $devices);
        } else {
            $first = true;
            foreach ($pages as $page) {
                if (!$first) {
                    sleep(2); // Rate-limit between API calls
                }
                $this->runTestForUrl($pageSpeed, $site, $page->url, $page->id, $devices);
                $first = false;
            }
        }

        $this->updateMonitorScores();
        $this->checkAlerts();
        $this->checkBudgets();
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

                // Run WP health checks on mobile test only
                if ($device === 'mobile') {
                    $wpHealth = $this->runWpHealthChecks($site, $results);
                    $test->update(['wp_health_checks' => $wpHealth]);
                }

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

            if (count($this->device === 'both' ? ['mobile', 'desktop'] : [$this->device]) > 1) {
                sleep(2);
            }
        }
    }

    private function runWpHealthChecks(Site $site, array $results): array
    {
        $checks = [];

        // Cache plugin detection
        $cachePlugins = ['wp-super-cache', 'w3-total-cache', 'wp-fastest-cache', 'litespeed-cache', 'wp-rocket', 'autoptimize', 'breeze', 'hummingbird', 'sg-cachepress', 'nitropack'];
        $thirdParty = $results['third_party_scripts'] ?? [];
        $allUrls = collect($thirdParty)->pluck('entity')->implode(' ');
        $hasCachePlugin = false;
        if ($site->plugins ?? false) {
            $pluginSlugs = collect(is_array($site->plugins) ? $site->plugins : [])->pluck('slug')->toArray();
            $hasCachePlugin = !empty(array_intersect($pluginSlugs, $cachePlugins));
        }
        $checks[] = [
            'key' => 'cache_plugin',
            'label' => 'Caching Plugin',
            'status' => $hasCachePlugin ? 'pass' : 'fail',
            'detail' => $hasCachePlugin ? 'Caching plugin detected' : 'No caching plugin detected',
            'recommendation' => $hasCachePlugin ? null : 'Install a caching plugin like WP Super Cache or LiteSpeed Cache',
        ];

        // Image optimization plugin
        $imagePlugins = ['imagify', 'smush', 'shortpixel-image-optimiser', 'ewww-image-optimizer', 'optimole-wp', 'webp-converter-for-media'];
        $hasImagePlugin = false;
        if ($site->plugins ?? false) {
            $pluginSlugs = collect(is_array($site->plugins) ? $site->plugins : [])->pluck('slug')->toArray();
            $hasImagePlugin = !empty(array_intersect($pluginSlugs, $imagePlugins));
        }
        $checks[] = [
            'key' => 'image_optimization',
            'label' => 'Image Optimization',
            'status' => $hasImagePlugin ? 'pass' : 'warn',
            'detail' => $hasImagePlugin ? 'Image optimization plugin detected' : 'No image optimization plugin detected',
            'recommendation' => $hasImagePlugin ? null : 'Consider installing an image optimization plugin like Imagify or ShortPixel',
        ];

        // Minification plugin
        $minPlugins = ['autoptimize', 'wp-rocket', 'litespeed-cache', 'fast-velocity-minify', 'hummingbird', 'sg-cachepress'];
        $hasMinPlugin = false;
        if ($site->plugins ?? false) {
            $pluginSlugs = collect(is_array($site->plugins) ? $site->plugins : [])->pluck('slug')->toArray();
            $hasMinPlugin = !empty(array_intersect($pluginSlugs, $minPlugins));
        }
        $checks[] = [
            'key' => 'minification',
            'label' => 'Asset Minification',
            'status' => $hasMinPlugin ? 'pass' : 'warn',
            'detail' => $hasMinPlugin ? 'Minification plugin detected' : 'No minification plugin detected',
            'recommendation' => $hasMinPlugin ? null : 'Consider enabling asset minification with Autoptimize or similar',
        ];

        // Plugin count
        $pluginCount = 0;
        if ($site->plugins ?? false) {
            $pluginCount = count(is_array($site->plugins) ? $site->plugins : []);
        }
        $pluginStatus = $pluginCount <= 25 ? 'pass' : ($pluginCount <= 40 ? 'warn' : 'fail');
        $checks[] = [
            'key' => 'plugin_count',
            'label' => 'Plugin Count',
            'status' => $pluginStatus,
            'detail' => "{$pluginCount} plugins installed",
            'recommendation' => $pluginStatus === 'pass' ? null : 'Consider reducing the number of active plugins to improve performance',
        ];

        // PHP version
        $phpVersion = $site->php_version ?? null;
        $phpStatus = 'warn';
        if ($phpVersion) {
            $phpStatus = version_compare($phpVersion, '8.1', '>=') ? 'pass' : 'fail';
        }
        $checks[] = [
            'key' => 'php_version',
            'label' => 'PHP Version',
            'status' => $phpStatus,
            'detail' => $phpVersion ? "PHP {$phpVersion}" : 'PHP version unknown',
            'recommendation' => $phpStatus === 'pass' ? null : 'Upgrade to PHP 8.1 or higher for better performance',
        ];

        // CDN detection from third-party scripts
        $cdnProviders = ['cloudflare', 'fastly', 'akamai', 'cloudfront', 'stackpath', 'bunnycdn', 'keycdn', 'sucuri'];
        $hasCdn = false;
        foreach ($thirdParty as $script) {
            $entity = strtolower($script['entity'] ?? '');
            foreach ($cdnProviders as $cdn) {
                if (str_contains($entity, $cdn)) {
                    $hasCdn = true;
                    break 2;
                }
            }
        }
        $checks[] = [
            'key' => 'cdn',
            'label' => 'CDN Detection',
            'status' => $hasCdn ? 'pass' : 'warn',
            'detail' => $hasCdn ? 'CDN detected' : 'No CDN detected',
            'recommendation' => $hasCdn ? null : 'Consider using a CDN like Cloudflare to improve load times globally',
        ];

        return $checks;
    }

    private function checkBudgets(): void
    {
        $budgets = $this->monitor->budgets;
        if (empty($budgets)) {
            return;
        }

        // Get latest completed mobile test for primary page
        $latestTest = $this->monitor->tests()
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        if (!$latestTest) {
            return;
        }

        // Get previous test to compare
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
            if (!isset($budgetMap[$key]) || $budget === null || $budget === '') {
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

        if (!empty($newViolations)) {
            NotifyBudgetViolation::dispatch($this->monitor, $newViolations, $latestTest);
        }
    }

    private function saveScreenshot(Site $site, PerformanceTest $test): void
    {
        try {
            $dataUri = $test->screenshot_final;
            if (!$dataUri || !str_contains($dataUri, 'base64,')) {
                return;
            }

            $base64 = explode('base64,', $dataUri, 2)[1];
            $imageData = base64_decode($base64);
            if (!$imageData) {
                return;
            }

            $src = @imagecreatefromstring($imageData);
            if (!$src) {
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
        $latestMobile = $this->monitor->tests()
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        $latestDesktop = $this->monitor->tests()
            ->where('device', 'desktop')
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereNull('performance_page_id')
                    ->orWhereHas('page', fn ($pq) => $pq->where('is_primary', true));
            })
            ->latest('tested_at')
            ->first();

        $nextTestAt = match ($this->monitor->frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            default => null, // manual
        };

        if ($nextTestAt && $this->monitor->test_time) {
            [$hour, $minute] = explode(':', $this->monitor->test_time);
            $nextTestAt->setTime((int) $hour, (int) $minute);
        }

        $this->monitor->update([
            'latest_mobile_score' => $latestMobile?->performance_score,
            'latest_desktop_score' => $latestDesktop?->performance_score,
            'last_tested_at' => now(),
            'next_test_at' => $nextTestAt,
        ]);
    }

    private function checkAlerts(): void
    {
        if (!$this->monitor->alert_on_score_drop) {
            return;
        }

        $threshold = $this->monitor->score_drop_threshold;

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
                ActivityLogger::performanceScoreDrop($this->monitor->site, 'mobile', $this->monitor->previous_mobile_score, $this->monitor->latest_mobile_score);
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
                ActivityLogger::performanceScoreDrop($this->monitor->site, 'desktop', $this->monitor->previous_desktop_score, $this->monitor->latest_desktop_score);
            }
        }
    }
}
