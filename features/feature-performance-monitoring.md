# SimpleAd Manager — Feature Spec: Performance Monitoring

---

## Overview

Performance monitoring for all managed sites using **Google PageSpeed Insights API** (free, no auth needed, uses Lighthouse under the hood). Tracks performance scores, Core Web Vitals, and Lighthouse recommendations for both Mobile and Desktop. Provides historical tracking, score trends, alerts on drops, and actionable optimization suggestions.

**Why PageSpeed Insights API instead of running Lighthouse locally:**
- Free, no infrastructure needed (no headless Chrome, no Puppeteer)
- Same Lighthouse engine, same scores
- Includes real CrUX (Chrome User Experience Report) field data when available
- Simple HTTP API call — perfect for a queued job
- Rate limit: ~25,000 queries/day (more than enough)

---

## PART 1: DATABASE SCHEMA

### Migration: `performance_monitors`

Per-site performance monitoring configuration:

```php
Schema::create('performance_monitors', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    $table->boolean('is_active')->default(true);
    $table->string('frequency')->default('daily'); // manual, daily, weekly
    $table->string('test_time')->default('04:00'); // HH:MM
    $table->integer('day_of_week')->nullable(); // 0-6 for weekly
    $table->string('test_url')->nullable(); // null = use site URL (homepage)
    
    // Alert config
    $table->boolean('alert_on_score_drop')->default(true);
    $table->integer('score_drop_threshold')->default(10); // alert if score drops by X points
    $table->boolean('alert_on_poor_vitals')->default(true);
    
    // Cached latest scores
    $table->integer('latest_mobile_score')->nullable();
    $table->integer('latest_desktop_score')->nullable();
    $table->integer('previous_mobile_score')->nullable(); // for trend comparison
    $table->integer('previous_desktop_score')->nullable();
    
    $table->timestamp('last_tested_at')->nullable();
    $table->timestamp('next_test_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['is_active', 'next_test_at']);
});
```

### Migration: `performance_tests`

Individual test results:

```php
Schema::create('performance_tests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('performance_monitor_id')->constrained()->onDelete('cascade');
    
    $table->string('device'); // mobile, desktop
    $table->string('url'); // tested URL
    
    // Lighthouse Scores (0-100)
    $table->integer('performance_score')->nullable();
    $table->integer('accessibility_score')->nullable();
    $table->integer('best_practices_score')->nullable();
    $table->integer('seo_score')->nullable();
    
    // Core Web Vitals (lab data)
    $table->decimal('fcp', 8, 3)->nullable(); // First Contentful Paint (seconds)
    $table->decimal('lcp', 8, 3)->nullable(); // Largest Contentful Paint (seconds)
    $table->decimal('cls', 8, 4)->nullable(); // Cumulative Layout Shift
    $table->integer('tbt')->nullable(); // Total Blocking Time (ms)
    $table->decimal('si', 8, 3)->nullable(); // Speed Index (seconds)
    $table->decimal('tti', 8, 3)->nullable(); // Time to Interactive (seconds)
    
    // Core Web Vitals (field data from CrUX — may be null for low-traffic sites)
    $table->decimal('field_fcp', 8, 3)->nullable();
    $table->decimal('field_lcp', 8, 3)->nullable();
    $table->decimal('field_cls', 8, 4)->nullable();
    $table->integer('field_inp')->nullable(); // Interaction to Next Paint (ms)
    $table->decimal('field_ttfb', 8, 3)->nullable(); // Time to First Byte (seconds)
    
    // Page stats
    $table->integer('total_requests')->nullable();
    $table->integer('total_size_bytes')->nullable(); // total transfer size
    $table->integer('html_size')->nullable();
    $table->integer('css_size')->nullable();
    $table->integer('js_size')->nullable();
    $table->integer('image_size')->nullable();
    $table->integer('font_size')->nullable();
    
    // Lighthouse recommendations (top opportunities)
    $table->json('opportunities')->nullable(); // [{title, description, savings_ms, savings_bytes}]
    $table->json('diagnostics')->nullable(); // [{title, description, details}]
    
    // Status
    $table->string('status')->default('pending'); // pending, running, completed, failed
    $table->text('error_message')->nullable();
    
    $table->string('lighthouse_version')->nullable();
    
    $table->timestamp('tested_at')->nullable();
    
    $table->index(['site_id', 'device', 'tested_at']);
    $table->index(['performance_monitor_id', 'tested_at']);
});
```

---

## PART 2: MODELS

```php
// app/Models/PerformanceMonitor.php

class PerformanceMonitor extends Model
{
    protected $casts = [
        'is_active' => 'boolean',
        'alert_on_score_drop' => 'boolean',
        'alert_on_poor_vitals' => 'boolean',
        'last_tested_at' => 'datetime',
        'next_test_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function tests(): HasMany { return $this->hasMany(PerformanceTest::class); }

    public function latestMobileTest(): HasOne {
        return $this->hasOne(PerformanceTest::class)
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->latestOfMany('tested_at');
    }

    public function latestDesktopTest(): HasOne {
        return $this->hasOne(PerformanceTest::class)
            ->where('device', 'desktop')
            ->where('status', 'completed')
            ->latestOfMany('tested_at');
    }
}

// app/Models/PerformanceTest.php

class PerformanceTest extends Model
{
    protected $casts = [
        'opportunities' => 'array',
        'diagnostics' => 'array',
        'tested_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function monitor(): BelongsTo { return $this->belongsTo(PerformanceMonitor::class, 'performance_monitor_id'); }

    // Score color: red (0-49), orange (50-89), green (90-100)
    public function getScoreColorAttribute(): string
    {
        $score = $this->performance_score;
        if ($score === null) return 'gray';
        if ($score >= 90) return 'green';
        if ($score >= 50) return 'orange';
        return 'red';
    }

    public function getScoreLabelAttribute(): string
    {
        $score = $this->performance_score;
        if ($score === null) return 'N/A';
        if ($score >= 90) return 'Good';
        if ($score >= 50) return 'Needs Improvement';
        return 'Poor';
    }

    // Format metric values for display
    public function formatMetric(string $metric): string
    {
        $value = $this->{$metric};
        if ($value === null) return '—';

        return match($metric) {
            'fcp', 'lcp', 'si', 'tti', 'field_fcp', 'field_lcp', 'field_ttfb' => number_format($value, 1) . ' s',
            'cls', 'field_cls' => number_format($value, 3),
            'tbt', 'field_inp' => number_format($value) . ' ms',
            'total_size_bytes', 'html_size', 'css_size', 'js_size', 'image_size', 'font_size' 
                => $this->formatBytes($value),
            default => (string) $value,
        };
    }

    // Metric color based on thresholds
    public function metricColor(string $metric): string
    {
        $value = $this->{$metric};
        if ($value === null) return 'gray';

        return match($metric) {
            'fcp', 'field_fcp' => $value <= 1.8 ? 'green' : ($value <= 3.0 ? 'orange' : 'red'),
            'lcp', 'field_lcp' => $value <= 2.5 ? 'green' : ($value <= 4.0 ? 'orange' : 'red'),
            'cls', 'field_cls' => $value <= 0.1 ? 'green' : ($value <= 0.25 ? 'orange' : 'red'),
            'tbt' => $value <= 200 ? 'green' : ($value <= 600 ? 'orange' : 'red'),
            'si' => $value <= 3.4 ? 'green' : ($value <= 5.8 ? 'orange' : 'red'),
            'tti' => $value <= 3.8 ? 'green' : ($value <= 7.3 ? 'orange' : 'red'),
            'field_inp' => $value <= 200 ? 'green' : ($value <= 500 ? 'orange' : 'red'),
            'field_ttfb' => $value <= 0.8 ? 'green' : ($value <= 1.8 ? 'orange' : 'red'),
            default => 'gray',
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
```

---

## PART 3: PAGESPEED INSIGHTS API SERVICE

```php
// app/Services/PageSpeedService.php

class PageSpeedService
{
    private string $apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private ?string $apiKey;

    public function __construct()
    {
        // API key is optional but increases rate limit
        $this->apiKey = config('services.pagespeed.api_key');
    }

    /**
     * Run a PageSpeed test for a URL
     */
    public function analyze(string $url, string $device = 'mobile'): array
    {
        $params = [
            'url' => $url,
            'strategy' => $device === 'mobile' ? 'MOBILE' : 'DESKTOP',
            'category' => ['PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO'],
        ];

        if ($this->apiKey) {
            $params['key'] = $this->apiKey;
        }

        $response = Http::timeout(120)->get($this->apiUrl, $params);

        if ($response->failed()) {
            throw new \Exception('PageSpeed API error: ' . $response->body());
        }

        $data = $response->json();
        return $this->parseResults($data);
    }

    private function parseResults(array $data): array
    {
        $lighthouse = $data['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];
        $audits = $lighthouse['audits'] ?? [];

        // Scores (0-1 from API, multiply by 100)
        $scores = [
            'performance_score' => isset($categories['performance']) ? (int) round($categories['performance']['score'] * 100) : null,
            'accessibility_score' => isset($categories['accessibility']) ? (int) round($categories['accessibility']['score'] * 100) : null,
            'best_practices_score' => isset($categories['best-practices']) ? (int) round($categories['best-practices']['score'] * 100) : null,
            'seo_score' => isset($categories['seo']) ? (int) round($categories['seo']['score'] * 100) : null,
        ];

        // Core Web Vitals (lab data)
        $labMetrics = [
            'fcp' => $this->extractMetricSeconds($audits, 'first-contentful-paint'),
            'lcp' => $this->extractMetricSeconds($audits, 'largest-contentful-paint'),
            'cls' => $this->extractMetricRaw($audits, 'cumulative-layout-shift'),
            'tbt' => $this->extractMetricMs($audits, 'total-blocking-time'),
            'si' => $this->extractMetricSeconds($audits, 'speed-index'),
            'tti' => $this->extractMetricSeconds($audits, 'interactive'),
        ];

        // Field data (CrUX)
        $fieldData = $this->parseFieldData($data['loadingExperience'] ?? []);

        // Page stats
        $resourceSummary = $audits['resource-summary']['details']['items'] ?? [];
        $pageStats = $this->parsePageStats($resourceSummary);

        // Top opportunities (things to fix)
        $opportunities = $this->parseOpportunities($audits, $categories['performance']['auditRefs'] ?? []);

        // Diagnostics
        $diagnostics = $this->parseDiagnostics($audits, $categories['performance']['auditRefs'] ?? []);

        return array_merge($scores, $labMetrics, $fieldData, $pageStats, [
            'opportunities' => $opportunities,
            'diagnostics' => $diagnostics,
            'lighthouse_version' => $lighthouse['lighthouseVersion'] ?? null,
        ]);
    }

    private function extractMetricSeconds(array $audits, string $key): ?float
    {
        $value = $audits[$key]['numericValue'] ?? null;
        return $value !== null ? round($value / 1000, 3) : null;
    }

    private function extractMetricMs(array $audits, string $key): ?int
    {
        $value = $audits[$key]['numericValue'] ?? null;
        return $value !== null ? (int) round($value) : null;
    }

    private function extractMetricRaw(array $audits, string $key): ?float
    {
        return isset($audits[$key]['numericValue']) ? round($audits[$key]['numericValue'], 4) : null;
    }

    private function parseFieldData(array $loadingExperience): array
    {
        $metrics = $loadingExperience['metrics'] ?? [];

        return [
            'field_fcp' => isset($metrics['FIRST_CONTENTFUL_PAINT_MS']) 
                ? round($metrics['FIRST_CONTENTFUL_PAINT_MS']['percentile'] / 1000, 3) : null,
            'field_lcp' => isset($metrics['LARGEST_CONTENTFUL_PAINT_MS']) 
                ? round($metrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] / 1000, 3) : null,
            'field_cls' => isset($metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']) 
                ? round($metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100, 4) : null,
            'field_inp' => isset($metrics['INTERACTION_TO_NEXT_PAINT']) 
                ? (int) $metrics['INTERACTION_TO_NEXT_PAINT']['percentile'] : null,
            'field_ttfb' => isset($metrics['EXPERIMENTAL_TIME_TO_FIRST_BYTE']) 
                ? round($metrics['EXPERIMENTAL_TIME_TO_FIRST_BYTE']['percentile'] / 1000, 3) : null,
        ];
    }

    private function parsePageStats(array $items): array
    {
        $stats = [
            'total_requests' => 0,
            'total_size_bytes' => 0,
            'html_size' => 0,
            'css_size' => 0,
            'js_size' => 0,
            'image_size' => 0,
            'font_size' => 0,
        ];

        foreach ($items as $item) {
            $type = $item['resourceType'] ?? '';
            $size = $item['transferSize'] ?? 0;
            $count = $item['requestCount'] ?? 0;

            if ($type === 'total') {
                $stats['total_requests'] = $count;
                $stats['total_size_bytes'] = $size;
            } elseif ($type === 'document') {
                $stats['html_size'] = $size;
            } elseif ($type === 'stylesheet') {
                $stats['css_size'] = $size;
            } elseif ($type === 'script') {
                $stats['js_size'] = $size;
            } elseif ($type === 'image') {
                $stats['image_size'] = $size;
            } elseif ($type === 'font') {
                $stats['font_size'] = $size;
            }
        }

        return $stats;
    }

    private function parseOpportunities(array $audits, array $auditRefs): array
    {
        $opportunities = [];

        foreach ($auditRefs as $ref) {
            if (($ref['group'] ?? '') !== 'load-opportunities') continue;

            $audit = $audits[$ref['id']] ?? null;
            if (!$audit || ($audit['score'] ?? 1) >= 0.9) continue;

            $details = $audit['details'] ?? [];
            $savings = $details['overallSavingsMs'] ?? null;
            $savingsBytes = $details['overallSavingsBytes'] ?? null;

            $opportunities[] = [
                'id' => $ref['id'],
                'title' => $audit['title'] ?? '',
                'description' => $audit['description'] ?? '',
                'score' => $audit['score'] ?? null,
                'savings_ms' => $savings ? (int) round($savings) : null,
                'savings_bytes' => $savingsBytes ? (int) $savingsBytes : null,
                'display_value' => $audit['displayValue'] ?? null,
            ];
        }

        // Sort by potential savings descending
        usort($opportunities, fn($a, $b) => ($b['savings_ms'] ?? 0) <=> ($a['savings_ms'] ?? 0));

        return array_slice($opportunities, 0, 10); // top 10
    }

    private function parseDiagnostics(array $audits, array $auditRefs): array
    {
        $diagnostics = [];

        foreach ($auditRefs as $ref) {
            if (($ref['group'] ?? '') !== 'diagnostics') continue;

            $audit = $audits[$ref['id']] ?? null;
            if (!$audit || ($audit['score'] ?? 1) >= 0.9) continue;

            $diagnostics[] = [
                'id' => $ref['id'],
                'title' => $audit['title'] ?? '',
                'description' => $audit['description'] ?? '',
                'display_value' => $audit['displayValue'] ?? null,
            ];
        }

        return array_slice($diagnostics, 0, 10);
    }
}
```

Config:
```php
// config/services.php
'pagespeed' => [
    'api_key' => env('PAGESPEED_API_KEY'), // optional, increases rate limit
],
```

---

## PART 4: TEST JOB

```php
// app/Jobs/RunPerformanceTest.php

class RunPerformanceTest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 min max (API can be slow)

    public function __construct(
        public PerformanceMonitor $monitor,
        public string $device = 'both' // mobile, desktop, both
    ) {}

    public function handle(PageSpeedService $pageSpeed): void
    {
        $site = $this->monitor->site;
        $url = $this->monitor->test_url ?: $site->url;
        $devices = $this->device === 'both' ? ['mobile', 'desktop'] : [$this->device];

        foreach ($devices as $device) {
            $test = PerformanceTest::create([
                'site_id' => $site->id,
                'performance_monitor_id' => $this->monitor->id,
                'device' => $device,
                'url' => $url,
                'status' => 'running',
                'tested_at' => now(),
            ]);

            try {
                $results = $pageSpeed->analyze($url, $device);

                $test->update(array_merge($results, [
                    'status' => 'completed',
                ]));

            } catch (\Exception $e) {
                $test->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        // Update cached scores on monitor
        $latestMobile = $this->monitor->tests()
            ->where('device', 'mobile')->where('status', 'completed')
            ->latest('tested_at')->first();
        $latestDesktop = $this->monitor->tests()
            ->where('device', 'desktop')->where('status', 'completed')
            ->latest('tested_at')->first();

        $this->monitor->update([
            'previous_mobile_score' => $this->monitor->latest_mobile_score,
            'previous_desktop_score' => $this->monitor->latest_desktop_score,
            'latest_mobile_score' => $latestMobile?->performance_score,
            'latest_desktop_score' => $latestDesktop?->performance_score,
            'last_tested_at' => now(),
            'next_test_at' => $this->calculateNextTest(),
        ]);

        // Check for score drop alerts
        $this->checkAlerts();
    }

    private function calculateNextTest(): Carbon
    {
        return match($this->monitor->frequency) {
            'daily' => now()->addDay()->setTimeFromTimeString($this->monitor->test_time),
            'weekly' => now()->addWeek()->startOfWeek()->addDays($this->monitor->day_of_week ?? 0)->setTimeFromTimeString($this->monitor->test_time),
            default => now()->addYear(), // manual — no auto schedule
        };
    }

    private function checkAlerts(): void
    {
        if (!$this->monitor->alert_on_score_drop) return;

        $threshold = $this->monitor->score_drop_threshold;

        // Check mobile score drop
        if ($this->monitor->previous_mobile_score && $this->monitor->latest_mobile_score) {
            $drop = $this->monitor->previous_mobile_score - $this->monitor->latest_mobile_score;
            if ($drop >= $threshold) {
                NotifyPerformanceDrop::dispatch(
                    $this->monitor,
                    'mobile',
                    $this->monitor->previous_mobile_score,
                    $this->monitor->latest_mobile_score
                );
            }
        }

        // Check desktop score drop
        if ($this->monitor->previous_desktop_score && $this->monitor->latest_desktop_score) {
            $drop = $this->monitor->previous_desktop_score - $this->monitor->latest_desktop_score;
            if ($drop >= $threshold) {
                NotifyPerformanceDrop::dispatch(
                    $this->monitor,
                    'desktop',
                    $this->monitor->previous_desktop_score,
                    $this->monitor->latest_desktop_score
                );
            }
        }
    }
}
```

### Scheduler

```php
// Run performance tests — check every hour for due tests
Schedule::call(function () {
    PerformanceMonitor::where('is_active', true)
        ->where(function ($q) {
            $q->whereNull('next_test_at')
              ->orWhere('next_test_at', '<=', now());
        })
        ->each(function ($monitor) {
            RunPerformanceTest::dispatch($monitor, 'both');
        });
})->hourly();
```

---

## PART 5: AUTO-CREATE MONITOR

When a site is created, auto-create a performance monitor:

```php
// Add to Site model boot() or observer (alongside existing SSL/Domain auto-creation)

$site->performanceMonitor()->create([
    'is_active' => true,
    'frequency' => 'daily',
    'test_time' => '04:00',
]);

// Run first test immediately
RunPerformanceTest::dispatch($site->performanceMonitor, 'both');
```

---

## PART 6: UI PAGES

### 6.1 Performance Page — Site Context (`/sites/{site}/performance`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Performance — simplead.ro                      [Run Test] [Settings]│
│  Last tested: 2 hours ago  •  Schedule: Daily at 04:00              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Mobile ──────────────────────────┐  ┌─ Desktop ────────────────┐ │
│  │                                    │  │                          │ │
│  │      ┌──────────────┐              │  │    ┌──────────────┐     │ │
│  │      │              │              │  │    │              │     │ │
│  │      │      71      │              │  │    │      97      │     │ │
│  │      │              │              │  │    │              │     │ │
│  │      └──────────────┘              │  │    └──────────────┘     │ │
│  │      Needs Improvement             │  │         Good            │ │
│  │                                    │  │                          │ │
│  │  ▲ FCP    3.2 s                    │  │  ● FCP    0.8 s         │ │
│  │  ● SI     3.2 s                    │  │  ● SI     0.8 s         │ │
│  │  ▲ LCP    6.3 s                    │  │  ■ LCP    1.3 s         │ │
│  │  ■ TTI    6.9 s                    │  │  ● TTI    1.5 s         │ │
│  │  ● TBT    20 ms                    │  │  ● TBT    30 ms         │ │
│  │  ● CLS    0.001                    │  │  ● CLS    0.019         │ │
│  │                                    │  │                          │ │
│  └────────────────────────────────────┘  └──────────────────────────┘ │
│                                                                       │
│  Legend: ▲ Poor (0-49)  ■ Needs Work (50-89)  ● Good (90-100)      │
│                                                                       │
│  ┌─ Score Categories ──────────────────────────────────────────────┐ │
│  │  Performance  │  Accessibility  │  Best Practices  │  SEO       │ │
│  │  71 (orange)  │  95 (green)     │  92 (green)      │  100 (green)│ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Field Data (Real Users — Chrome UX Report) ────────────────────┐ │
│  │  These are real user metrics collected from Chrome browsers.     │ │
│  │                                                                  │ │
│  │  FCP: 1.4 s (●)  │  LCP: 2.1 s (●)  │  CLS: 0.05 (●)        │ │
│  │  INP: 180 ms (●) │  TTFB: 0.6 s (●)                           │ │
│  │                                                                  │ │
│  │  (If not available: "No field data available. CrUX data requires│ │
│  │   sufficient real-user traffic from Chrome browsers.")           │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Page Weight Breakdown ─────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Total: 1.8 MB (42 requests)                                    │ │
│  │                                                                  │ │
│  │  ████████████████████ Images     820 KB  (45%)                  │ │
│  │  ██████████████       JavaScript  540 KB  (30%)                 │ │
│  │  ████                 CSS         180 KB  (10%)                 │ │
│  │  ███                  Fonts       140 KB  (8%)                  │ │
│  │  ██                   HTML        90 KB   (5%)                  │ │
│  │  █                    Other       30 KB   (2%)                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Top Opportunities ─────────────────────────────────────────────┐ │
│  │  These changes could improve your page load time:               │ │
│  │                                                                  │ │
│  │  1. Serve images in next-gen formats          Save ~1.2 s       │ │
│  │     Use WebP or AVIF instead of JPEG/PNG                        │ │
│  │                                                                  │ │
│  │  2. Eliminate render-blocking resources        Save ~0.8 s       │ │
│  │     2 CSS and 3 JS files are blocking first paint               │ │
│  │                                                                  │ │
│  │  3. Reduce unused JavaScript                   Save ~0.5 s       │ │
│  │     320 KB of JavaScript is unused on this page                 │ │
│  │                                                                  │ │
│  │  4. Properly size images                       Save ~0.3 s       │ │
│  │     5 images could save 280 KB by resizing                      │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Score History ─────────────────────────────────────────────────┐ │
│  │  [7d] [30d] [90d]                                               │ │
│  │                                                                  │ │
│  │  100 ─┬──────────────────────────────────────── Desktop         │ │
│  │       │  ───────────────────────────────────────                 │ │
│  │   75 ─┤                                                          │ │
│  │       │       ╭──────╮                                          │ │
│  │   50 ─┤  ─────╯      ╰────────────────────── Mobile            │ │
│  │       │                                                          │ │
│  │   25 ─┤                                                          │ │
│  │       │                                                          │ │
│  │    0 ─┴──────────────────────────────────────────────            │ │
│  │       Jan 5  Jan 10  Jan 15  Jan 20  Jan 25  Jan 30  Feb 2     │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Test History ──────────────────────────────────────────────────┐ │
│  │  Date          │ Device  │ Score │ FCP   │ LCP   │ CLS    │ TBT│ │
│  │ ───────────────────────────────────────────────────────────────  │ │
│  │  Feb 2, 04:00  │ Mobile  │ 71    │ 3.2 s │ 6.3 s │ 0.001  │ 20ms│
│  │  Feb 2, 04:00  │ Desktop │ 97    │ 0.8 s │ 1.3 s │ 0.019  │ 30ms│
│  │  Feb 1, 04:00  │ Mobile  │ 68    │ 3.5 s │ 6.8 s │ 0.002  │ 25ms│
│  │  Feb 1, 04:00  │ Desktop │ 96    │ 0.9 s │ 1.4 s │ 0.018  │ 35ms│
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.2 Score Circle Component

The score display should be a circular gauge (like Lighthouse reports):

```blade
{{-- Circular score gauge component --}}
{{-- Use SVG circle with stroke-dasharray for the gauge --}}
<div class="relative inline-flex items-center justify-center w-32 h-32">
    <svg class="w-full h-full transform -rotate-90" viewBox="0 0 120 120">
        <!-- Background circle -->
        <circle cx="60" cy="60" r="54" fill="none" stroke="#e5e7eb" stroke-width="8" />
        <!-- Score arc -->
        <circle cx="60" cy="60" r="54" fill="none"
                stroke="{{ $color }}"
                stroke-width="8"
                stroke-dasharray="{{ $score * 3.39 }} 339.292"
                stroke-linecap="round" />
    </svg>
    <div class="absolute flex flex-col items-center">
        <span class="text-3xl font-bold {{ $textColor }}">{{ $score }}</span>
    </div>
</div>
```

Colors:
- 90-100: `#22C55E` (green)
- 50-89: `#F59E0B` (orange/amber)
- 0-49: `#EF4444` (red)

### 6.3 Update Site Card

Show performance score on the site card:

```blade
{{-- Performance score on site card --}}
@if($site->performanceMonitor)
    <div class="flex items-center gap-1" title="Performance: Mobile {{ $site->performanceMonitor->latest_mobile_score ?? '—' }} / Desktop {{ $site->performanceMonitor->latest_desktop_score ?? '—' }}">
        <svg class="h-3.5 w-3.5 {{ match(true) {
            ($site->performanceMonitor->latest_mobile_score ?? 0) >= 90 => 'text-green-500',
            ($site->performanceMonitor->latest_mobile_score ?? 0) >= 50 => 'text-yellow-500',
            default => 'text-red-500',
        } }}" ...><!-- speed icon --></svg>
        <span class="text-xs">{{ $site->performanceMonitor->latest_mobile_score ?? '—' }}</span>
    </div>
@endif
```

### 6.4 Update Site Overview

Add performance summary card:

```blade
<x-ui.card>
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500">Performance</p>
            <div class="mt-1 flex items-center gap-4">
                <div>
                    <span class="text-xs text-gray-500">Mobile</span>
                    <p class="text-lg font-semibold {{ scoreColor($monitor->latest_mobile_score) }}">
                        {{ $monitor->latest_mobile_score ?? '—' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">Desktop</span>
                    <p class="text-lg font-semibold {{ scoreColor($monitor->latest_desktop_score) }}">
                        {{ $monitor->latest_desktop_score ?? '—' }}
                    </p>
                </div>
            </div>
        </div>
        <div class="text-right text-xs text-gray-500">
            Tested {{ $monitor->last_tested_at?->diffForHumans() ?? 'never' }}
        </div>
    </div>
</x-ui.card>
```

---

## PART 7: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   └── SitePerformance.php              # Main performance page
│
├── Components/
│   ├── ScoreGauge.php                   # SVG circular score gauge
│   ├── MetricsGrid.php                  # Core Web Vitals grid (FCP, LCP, etc.)
│   ├── PageWeightChart.php              # Page weight breakdown bar chart
│   ├── OpportunitiesList.php            # Top optimization opportunities
│   ├── ScoreHistoryChart.php            # Historical score line chart
│   └── PerformanceTestHistory.php       # Test history table
```

---

## PART 8: IMPLEMENTATION CHECKLIST

### Database & Models
- [ ] Create migration: performance_monitors
- [ ] Create migration: performance_tests
- [ ] Create model: PerformanceMonitor (with casts, relationships, scopes)
- [ ] Create model: PerformanceTest (with casts, formatMetric, metricColor helpers)
- [ ] Add performanceMonitor relationship to Site model

### Service & Jobs
- [ ] Create PageSpeedService (Google PageSpeed Insights API integration)
- [ ] Create RunPerformanceTest job (test both devices, update cached scores, check alerts)
- [ ] Create NotifyPerformanceDrop job (notification via existing channels)
- [ ] Add scheduler entry (hourly check for due tests)
- [ ] Add optional PAGESPEED_API_KEY to .env and config/services.php

### Auto-creation
- [ ] Auto-create performance monitor when site is created
- [ ] Run first test immediately on creation
- [ ] Ensure existing sites get monitors (artisan command or seeder)

### UI Pages
- [ ] Build SitePerformance page with full layout
- [ ] Build ScoreGauge component (SVG circular gauge with score colors)
- [ ] Build Core Web Vitals grid with metric colors and thresholds
- [ ] Build Field Data section (CrUX data when available)
- [ ] Build Page Weight Breakdown chart (horizontal stacked bar)
- [ ] Build Opportunities list (sorted by potential savings)
- [ ] Build Score History line chart (mobile + desktop lines, 7d/30d/90d toggle)
- [ ] Build Test History table
- [ ] "Run Test" button (manual test trigger with loading state)
- [ ] Settings popover/modal (frequency, test URL, alert threshold)

### Integration
- [ ] Wire performance score to site card
- [ ] Wire performance summary to site overview
- [ ] Connect score drop alerts to existing notification channels
- [ ] Add performance data to dashboard summary
