# SimpleAd Manager — Feature Spec: Link Checker

---

## Overview

Automated broken link detection for all managed WordPress sites. Crawls pages, checks internal and external links, detects 404s, redirects, timeouts, and SSL errors. Provides a dashboard to review and fix broken links, with scheduled scans and alerts.

---

## PART 1: DATABASE SCHEMA

### Migration: `link_monitors`

Per-site link monitoring configuration:

```php
Schema::create('link_monitors', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    $table->boolean('is_active')->default(true);
    $table->string('frequency')->default('weekly'); // manual, daily, weekly, monthly
    $table->string('scan_time')->default('02:00'); // HH:MM
    $table->integer('day_of_week')->nullable(); // 0-6 for weekly
    
    // Scan settings
    $table->integer('max_pages')->default(500); // max pages to crawl per scan
    $table->integer('max_depth')->default(5); // max link depth from homepage
    $table->boolean('check_external')->default(true); // check external links too
    $table->boolean('check_images')->default(true); // check image src URLs
    $table->integer('timeout_seconds')->default(15);
    $table->string('user_agent')->nullable(); // custom user agent, null = default
    
    // Exclusions
    $table->json('exclude_paths')->nullable(); // ["/wp-admin/*", "/feed/*"]
    $table->json('exclude_domains')->nullable(); // ["facebook.com", "twitter.com"]
    
    // Alert config
    $table->boolean('alert_on_broken')->default(true);
    $table->integer('alert_threshold')->default(5); // alert if X+ new broken links
    
    // Cached stats
    $table->integer('total_links')->default(0);
    $table->integer('broken_links')->default(0);
    $table->integer('redirects')->default(0);
    $table->integer('pages_scanned')->default(0);
    
    $table->timestamp('last_scan_at')->nullable();
    $table->timestamp('next_scan_at')->nullable();
    $table->string('last_scan_status')->nullable(); // completed, failed, in_progress
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['is_active', 'next_scan_at']);
});
```

### Migration: `link_scans`

Individual scan runs:

```php
Schema::create('link_scans', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('link_monitor_id')->constrained()->onDelete('cascade');
    
    $table->string('status')->default('pending'); // pending, in_progress, completed, failed
    $table->string('trigger')->default('scheduled'); // scheduled, manual
    
    // Stats
    $table->integer('pages_scanned')->default(0);
    $table->integer('links_checked')->default(0);
    $table->integer('broken_count')->default(0);
    $table->integer('redirect_count')->default(0);
    $table->integer('timeout_count')->default(0);
    $table->integer('ok_count')->default(0);
    
    // Progress tracking (for real-time updates)
    $table->integer('progress_percent')->default(0);
    $table->string('progress_message')->nullable();
    
    $table->text('error_message')->nullable();
    
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->integer('duration_seconds')->nullable();
    
    $table->timestamps();
    
    $table->index(['link_monitor_id', 'created_at']);
});
```

### Migration: `links`

All discovered links:

```php
Schema::create('links', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('link_scan_id')->constrained()->onDelete('cascade');
    
    // Link info
    $table->string('url', 2048); // the target URL
    $table->string('url_hash', 64)->index(); // SHA256 hash for fast lookups
    $table->string('type'); // internal, external
    $table->string('link_type'); // anchor, image, script, stylesheet, iframe
    
    // Source info (where this link was found)
    $table->string('source_url', 2048); // page URL where link was found
    $table->string('source_title')->nullable(); // page title
    $table->string('anchor_text')->nullable(); // link text (for anchors)
    $table->string('element')->nullable(); // a, img, script, link, iframe
    
    // Status
    $table->string('status'); // ok, broken, redirect, timeout, ssl_error, dns_error, skipped
    $table->integer('http_code')->nullable(); // 200, 404, 301, 302, 500, etc.
    $table->string('final_url', 2048)->nullable(); // after redirects
    $table->integer('redirect_count')->default(0);
    $table->integer('response_time_ms')->nullable();
    $table->text('error_message')->nullable();
    
    // For redirects
    $table->boolean('is_permanent_redirect')->nullable(); // 301 vs 302
    
    // Tracking
    $table->boolean('is_dismissed')->default(false); // user dismissed this issue
    $table->string('dismissed_reason')->nullable();
    $table->timestamp('first_detected_at')->nullable();
    $table->timestamp('last_checked_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['site_id', 'status']);
    $table->index(['link_scan_id', 'status']);
    $table->index(['site_id', 'url_hash']);
});
```

---

## PART 2: MODELS

```php
// app/Models/LinkMonitor.php

class LinkMonitor extends Model
{
    protected $casts = [
        'is_active' => 'boolean',
        'check_external' => 'boolean',
        'check_images' => 'boolean',
        'alert_on_broken' => 'boolean',
        'exclude_paths' => 'array',
        'exclude_domains' => 'array',
        'last_scan_at' => 'datetime',
        'next_scan_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function scans(): HasMany { return $this->hasMany(LinkScan::class); }
    
    public function latestScan(): HasOne
    {
        return $this->hasOne(LinkScan::class)->latestOfMany();
    }

    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(LinkScan::class)
            ->where('status', 'completed')
            ->latestOfMany();
    }
}

// app/Models/LinkScan.php

class LinkScan extends Model
{
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function monitor(): BelongsTo { return $this->belongsTo(LinkMonitor::class, 'link_monitor_id'); }
    public function links(): HasMany { return $this->hasMany(Link::class); }

    public function brokenLinks(): HasMany
    {
        return $this->hasMany(Link::class)->whereIn('status', ['broken', 'timeout', 'ssl_error', 'dns_error']);
    }

    public function redirectLinks(): HasMany
    {
        return $this->hasMany(Link::class)->where('status', 'redirect');
    }
}

// app/Models/Link.php

class Link extends Model
{
    protected $casts = [
        'is_dismissed' => 'boolean',
        'is_permanent_redirect' => 'boolean',
        'first_detected_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function scan(): BelongsTo { return $this->belongsTo(LinkScan::class, 'link_scan_id'); }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'ok' => 'green',
            'redirect' => 'yellow',
            'broken', 'timeout', 'ssl_error', 'dns_error' => 'red',
            'skipped' => 'gray',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'ok' => 'OK',
            'redirect' => $this->is_permanent_redirect ? '301 Redirect' : '302 Redirect',
            'broken' => "Broken ({$this->http_code})",
            'timeout' => 'Timeout',
            'ssl_error' => 'SSL Error',
            'dns_error' => 'DNS Error',
            'skipped' => 'Skipped',
            default => 'Unknown',
        };
    }
}
```

---

## PART 3: LINK CHECKER SERVICE

```php
// app/Services/LinkCheckerService.php

class LinkCheckerService
{
    private array $checkedUrls = [];
    private array $pagesToCrawl = [];
    private array $foundLinks = [];
    private int $pagesScanned = 0;
    private Site $site;
    private LinkMonitor $monitor;
    private LinkScan $scan;

    public function __construct(
        private int $maxPages = 500,
        private int $maxDepth = 5,
        private int $timeout = 15,
        private bool $checkExternal = true,
        private bool $checkImages = true,
        private array $excludePaths = [],
        private array $excludeDomains = [],
    ) {}

    public function scan(Site $site, LinkMonitor $monitor, LinkScan $scan): void
    {
        $this->site = $site;
        $this->monitor = $monitor;
        $this->scan = $scan;

        $baseUrl = rtrim($site->url, '/');
        $baseDomain = parse_url($baseUrl, PHP_URL_HOST);

        // Start with homepage
        $this->pagesToCrawl[] = ['url' => $baseUrl, 'depth' => 0];
        $this->checkedUrls[$baseUrl] = true;

        while (!empty($this->pagesToCrawl) && $this->pagesScanned < $this->maxPages) {
            $page = array_shift($this->pagesToCrawl);
            
            if ($page['depth'] > $this->maxDepth) continue;

            $this->crawlPage($page['url'], $page['depth'], $baseDomain);
            $this->pagesScanned++;

            // Update progress
            $progress = min(90, (int)(($this->pagesScanned / $this->maxPages) * 90));
            $this->scan->update([
                'progress_percent' => $progress,
                'progress_message' => "Scanning page {$this->pagesScanned}: " . Str::limit($page['url'], 50),
                'pages_scanned' => $this->pagesScanned,
            ]);
        }

        // Now check all external links
        $this->scan->update([
            'progress_percent' => 92,
            'progress_message' => 'Checking external links...',
        ]);
        $this->checkExternalLinks();

        // Calculate final stats
        $this->finalize();
    }

    private function crawlPage(string $url, int $depth, string $baseDomain): void
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['User-Agent' => $this->getUserAgent()])
                ->get($url);

            if (!$response->successful()) return;

            $html = $response->body();
            $contentType = $response->header('Content-Type');

            // Only parse HTML pages
            if (!str_contains($contentType ?? '', 'text/html')) return;

            $dom = new \DOMDocument();
            @$dom->loadHTML($html, LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // Get page title
            $titleNode = $xpath->query('//title')->item(0);
            $pageTitle = $titleNode ? trim($titleNode->textContent) : null;

            // Find all links
            $this->extractLinks($xpath, $url, $pageTitle, $baseDomain, $depth);

        } catch (\Exception $e) {
            // Page couldn't be crawled — skip silently
        }
    }

    private function extractLinks(\DOMXPath $xpath, string $sourceUrl, ?string $pageTitle, string $baseDomain, int $depth): void
    {
        // Anchor links
        foreach ($xpath->query('//a[@href]') as $node) {
            $href = $node->getAttribute('href');
            $anchorText = trim($node->textContent);
            $this->processLink($href, $sourceUrl, $pageTitle, $anchorText, 'a', 'anchor', $baseDomain, $depth);
        }

        // Images
        if ($this->checkImages) {
            foreach ($xpath->query('//img[@src]') as $node) {
                $src = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');
                $this->processLink($src, $sourceUrl, $pageTitle, $alt, 'img', 'image', $baseDomain, $depth);
            }
        }

        // Scripts
        foreach ($xpath->query('//script[@src]') as $node) {
            $src = $node->getAttribute('src');
            $this->processLink($src, $sourceUrl, $pageTitle, null, 'script', 'script', $baseDomain, $depth);
        }

        // Stylesheets
        foreach ($xpath->query('//link[@rel="stylesheet"][@href]') as $node) {
            $href = $node->getAttribute('href');
            $this->processLink($href, $sourceUrl, $pageTitle, null, 'link', 'stylesheet', $baseDomain, $depth);
        }
    }

    private function processLink(string $href, string $sourceUrl, ?string $pageTitle, ?string $anchorText, string $element, string $linkType, string $baseDomain, int $depth): void
    {
        // Skip empty, javascript:, mailto:, tel:, #anchors
        if (empty($href) || 
            str_starts_with($href, '#') ||
            str_starts_with($href, 'javascript:') ||
            str_starts_with($href, 'mailto:') ||
            str_starts_with($href, 'tel:') ||
            str_starts_with($href, 'data:')) {
            return;
        }

        // Resolve relative URLs
        $absoluteUrl = $this->resolveUrl($href, $sourceUrl);
        if (!$absoluteUrl) return;

        // Check exclusions
        if ($this->isExcluded($absoluteUrl)) return;

        $urlDomain = parse_url($absoluteUrl, PHP_URL_HOST);
        $isInternal = $this->isInternalUrl($urlDomain, $baseDomain);
        $type = $isInternal ? 'internal' : 'external';

        // Skip external if not configured to check
        if (!$isInternal && !$this->checkExternal) return;

        // Skip already checked URLs
        $urlHash = hash('sha256', $absoluteUrl);
        if (isset($this->checkedUrls[$absoluteUrl])) {
            // Still record the link occurrence (different source page)
            $this->foundLinks[] = [
                'url' => $absoluteUrl,
                'url_hash' => $urlHash,
                'type' => $type,
                'link_type' => $linkType,
                'source_url' => $sourceUrl,
                'source_title' => $pageTitle,
                'anchor_text' => $anchorText ? Str::limit($anchorText, 255) : null,
                'element' => $element,
                'status' => 'pending', // will be updated with cached result
            ];
            return;
        }

        $this->checkedUrls[$absoluteUrl] = true;

        // For internal HTML pages, add to crawl queue
        if ($isInternal && $element === 'a' && $depth < $this->maxDepth) {
            $this->pagesToCrawl[] = ['url' => $absoluteUrl, 'depth' => $depth + 1];
        }

        // Record the link
        $this->foundLinks[] = [
            'url' => $absoluteUrl,
            'url_hash' => $urlHash,
            'type' => $type,
            'link_type' => $linkType,
            'source_url' => $sourceUrl,
            'source_title' => $pageTitle,
            'anchor_text' => $anchorText ? Str::limit($anchorText, 255) : null,
            'element' => $element,
            'status' => 'pending',
        ];
    }

    private function checkExternalLinks(): void
    {
        $pending = collect($this->foundLinks)->where('status', 'pending');
        $total = $pending->count();
        $checked = 0;

        foreach ($pending as $index => $link) {
            $result = $this->checkUrl($link['url']);
            $this->foundLinks[$index] = array_merge($link, $result);
            
            $checked++;
            if ($checked % 10 === 0) {
                $this->scan->update([
                    'progress_message' => "Checking link {$checked} of {$total}...",
                ]);
            }
        }
    }

    private function checkUrl(string $url): array
    {
        $result = [
            'status' => 'ok',
            'http_code' => null,
            'final_url' => null,
            'redirect_count' => 0,
            'response_time_ms' => null,
            'error_message' => null,
            'is_permanent_redirect' => null,
        ];

        try {
            $startTime = microtime(true);
            
            $response = Http::timeout($this->timeout)
                ->withHeaders(['User-Agent' => $this->getUserAgent()])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 10,
                        'track_redirects' => true,
                    ],
                    'verify' => true,
                ])
                ->get($url);

            $result['response_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $result['http_code'] = $response->status();

            // Check for redirects
            $redirectHistory = $response->handlerStats()['redirect_url'] ?? null;
            if ($response->effectiveUri() && (string)$response->effectiveUri() !== $url) {
                $result['final_url'] = (string)$response->effectiveUri();
                $result['redirect_count'] = 1; // simplified
                $result['status'] = 'redirect';
                $result['is_permanent_redirect'] = in_array($response->status(), [301, 308]);
            }

            // Check status codes
            if ($response->status() >= 400) {
                $result['status'] = 'broken';
            } elseif ($response->status() >= 300 && $response->status() < 400) {
                $result['status'] = 'redirect';
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $result['status'] = str_contains($e->getMessage(), 'SSL') ? 'ssl_error' : 'timeout';
            $result['error_message'] = Str::limit($e->getMessage(), 255);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Could not resolve host')) {
                $result['status'] = 'dns_error';
            } else {
                $result['status'] = 'broken';
            }
            $result['error_message'] = Str::limit($e->getMessage(), 255);
        }

        return $result;
    }

    private function finalize(): void
    {
        // Save all links to database
        $brokenCount = 0;
        $redirectCount = 0;
        $timeoutCount = 0;
        $okCount = 0;

        foreach ($this->foundLinks as $linkData) {
            Link::create([
                'site_id' => $this->site->id,
                'link_scan_id' => $this->scan->id,
                'url' => Str::limit($linkData['url'], 2048),
                'url_hash' => $linkData['url_hash'],
                'type' => $linkData['type'],
                'link_type' => $linkData['link_type'],
                'source_url' => Str::limit($linkData['source_url'], 2048),
                'source_title' => $linkData['source_title'],
                'anchor_text' => $linkData['anchor_text'],
                'element' => $linkData['element'],
                'status' => $linkData['status'],
                'http_code' => $linkData['http_code'] ?? null,
                'final_url' => isset($linkData['final_url']) ? Str::limit($linkData['final_url'], 2048) : null,
                'redirect_count' => $linkData['redirect_count'] ?? 0,
                'response_time_ms' => $linkData['response_time_ms'] ?? null,
                'error_message' => $linkData['error_message'] ?? null,
                'is_permanent_redirect' => $linkData['is_permanent_redirect'] ?? null,
                'first_detected_at' => now(),
                'last_checked_at' => now(),
            ]);

            match($linkData['status']) {
                'broken', 'ssl_error', 'dns_error' => $brokenCount++,
                'redirect' => $redirectCount++,
                'timeout' => $timeoutCount++,
                'ok' => $okCount++,
                default => null,
            };
        }

        // Update scan stats
        $this->scan->update([
            'status' => 'completed',
            'progress_percent' => 100,
            'progress_message' => 'Scan complete',
            'links_checked' => count($this->foundLinks),
            'broken_count' => $brokenCount,
            'redirect_count' => $redirectCount,
            'timeout_count' => $timeoutCount,
            'ok_count' => $okCount,
            'completed_at' => now(),
            'duration_seconds' => now()->diffInSeconds($this->scan->started_at),
        ]);

        // Update monitor cached stats
        $this->monitor->update([
            'total_links' => count($this->foundLinks),
            'broken_links' => $brokenCount + $timeoutCount,
            'redirects' => $redirectCount,
            'pages_scanned' => $this->pagesScanned,
            'last_scan_at' => now(),
            'last_scan_status' => 'completed',
        ]);
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Already absolute
        if (preg_match('/^https?:\/\//i', $href)) {
            return $href;
        }

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        $baseParts = parse_url($baseUrl);
        $base = $baseParts['scheme'] . '://' . $baseParts['host'];

        // Absolute path
        if (str_starts_with($href, '/')) {
            return $base . $href;
        }

        // Relative path
        $basePath = $baseParts['path'] ?? '/';
        $baseDir = dirname($basePath);
        return $base . rtrim($baseDir, '/') . '/' . $href;
    }

    private function isInternalUrl(string $urlDomain, string $baseDomain): bool
    {
        return $urlDomain === $baseDomain || 
               $urlDomain === 'www.' . $baseDomain ||
               'www.' . $urlDomain === $baseDomain;
    }

    private function isExcluded(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $domain = parse_url($url, PHP_URL_HOST) ?? '';

        foreach ($this->excludePaths as $pattern) {
            if (fnmatch($pattern, $path)) return true;
        }

        foreach ($this->excludeDomains as $excludeDomain) {
            if (str_contains($domain, $excludeDomain)) return true;
        }

        return false;
    }

    private function getUserAgent(): string
    {
        return 'SimpleAd Link Checker/1.0 (+https://manager.simplead.ro)';
    }
}
```

---

## PART 4: SCAN JOB

```php
// app/Jobs/RunLinkScan.php

class RunLinkScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour max

    public function __construct(
        public LinkMonitor $monitor,
        public string $trigger = 'scheduled'
    ) {}

    public function handle(): void
    {
        $site = $this->monitor->site;

        // Create scan record
        $scan = LinkScan::create([
            'site_id' => $site->id,
            'link_monitor_id' => $this->monitor->id,
            'status' => 'in_progress',
            'trigger' => $this->trigger,
            'progress_percent' => 0,
            'progress_message' => 'Starting scan...',
            'started_at' => now(),
        ]);

        try {
            $service = new LinkCheckerService(
                maxPages: $this->monitor->max_pages,
                maxDepth: $this->monitor->max_depth,
                timeout: $this->monitor->timeout_seconds,
                checkExternal: $this->monitor->check_external,
                checkImages: $this->monitor->check_images,
                excludePaths: $this->monitor->exclude_paths ?? [],
                excludeDomains: $this->monitor->exclude_domains ?? [],
            );

            $service->scan($site, $this->monitor, $scan);

            // Check for alerts
            $this->checkAlerts($scan);

            // Schedule next scan
            $this->scheduleNext();

        } catch (\Exception $e) {
            $scan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->monitor->update([
                'last_scan_status' => 'failed',
            ]);

            throw $e;
        }
    }

    private function checkAlerts(LinkScan $scan): void
    {
        if (!$this->monitor->alert_on_broken) return;

        $brokenCount = $scan->broken_count + $scan->timeout_count;

        if ($brokenCount >= $this->monitor->alert_threshold) {
            NotifyBrokenLinks::dispatch($this->monitor, $scan, $brokenCount);
        }
    }

    private function scheduleNext(): void
    {
        $next = match($this->monitor->frequency) {
            'daily' => now()->addDay()->setTimeFromTimeString($this->monitor->scan_time),
            'weekly' => now()->addWeek()->startOfWeek()->addDays($this->monitor->day_of_week ?? 0)->setTimeFromTimeString($this->monitor->scan_time),
            'monthly' => now()->addMonth()->startOfMonth()->setTimeFromTimeString($this->monitor->scan_time),
            default => null, // manual
        };

        $this->monitor->update(['next_scan_at' => $next]);
    }
}
```

### Scheduler

```php
// Check for due link scans every hour
Schedule::call(function () {
    LinkMonitor::where('is_active', true)
        ->where(function ($q) {
            $q->whereNull('next_scan_at')
              ->orWhere('next_scan_at', '<=', now());
        })
        ->each(function ($monitor) {
            RunLinkScan::dispatch($monitor, 'scheduled');
        });
})->hourly();
```

---

## PART 5: AUTO-CREATE MONITOR

When a site is created:

```php
// Add to Site model boot() or observer

$site->linkMonitor()->create([
    'is_active' => true,
    'frequency' => 'weekly',
    'scan_time' => '02:00',
    'day_of_week' => 0, // Sunday
]);

// Optionally run first scan immediately (or defer — scans can take a while)
// RunLinkScan::dispatch($site->linkMonitor, 'manual');
```

---

## PART 6: UI PAGES

### 6.1 Links Page — Site Context (`/sites/{site}/links`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Links — simplead.ro                              [Scan Now] [Settings]│
│  Last scanned: 2 days ago  •  Schedule: Weekly (Sundays at 02:00)  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Summary ───────────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐        │ │
│  │  │   1,245  │  │     12   │  │     28   │  │     3    │        │ │
│  │  │  Links   │  │  Broken  │  │ Redirects│  │ Timeouts │        │ │
│  │  │  Total   │  │   🔴     │  │   🟡     │  │   🔴     │        │ │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────┘        │ │
│  │                                                                  │ │
│  │  Pages scanned: 156                                              │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  [All (1,245)] [Broken (12)] [Redirects (28)] [Timeouts (3)] [OK]   │
│                                                                       │
│  🔍 Search URL or anchor text...                   Type: [All ▼]    │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  Status │ URL                        │ Found On       │ Actions │ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  🔴 404 │ /old-page-removed          │ /blog/post-1   │ [✗][👁] │ │
│  │         │ "Read more about this"     │                │         │ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  🔴 404 │ /wp-content/uploads/old.pdf│ /resources     │ [✗][👁] │ │
│  │         │ Image: missing-file.pdf    │                │         │ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  🟡 301 │ /blog → /news              │ /about         │ [✗][👁] │ │
│  │         │ "Visit our blog"           │ (update link)  │         │ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  🔴 DNS │ https://old-partner.com    │ /partners      │ [✗][👁] │ │
│  │         │ "Partner website" (external)│                │         │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  Actions: [✗] = Dismiss (hide from list), [👁] = View page          │
│                                                                       │
│  ┌─ Scan History ──────────────────────────────────────────────────┐ │
│  │  Date           │ Links  │ Broken │ Redirects │ Duration │Status│ │
│  │ ───────────────────────────────────────────────────────────────  │ │
│  │  Feb 2, 02:00   │ 1,245  │ 12     │ 28        │ 8 min    │ ✅  │ │
│  │  Jan 26, 02:00  │ 1,198  │ 8      │ 25        │ 7 min    │ ✅  │ │
│  │  Jan 19, 02:00  │ 1,156  │ 6      │ 24        │ 6 min    │ ✅  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.2 Scan Progress (while scanning)

When a scan is in progress, show at the top:

```
┌─────────────────────────────────────────────────────────────────────┐
│  🔄 Scan in Progress                                                │
│                                                                     │
│  ████████████████████████░░░░░░░░░░░  65%                         │
│                                                                     │
│  Scanning page 98: /blog/how-to-optimize-wordpress...              │
│  Found: 823 links (8 broken so far)                                │
│                                                                     │
│  Started 4 minutes ago                                              │
└─────────────────────────────────────────────────────────────────────┘
```

Use `wire:poll.3s` while scan is in_progress.

### 6.3 Settings Modal

```
┌─────────────────────────────────────────────────────────────────────┐
│  Link Checker Settings                                       [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Schedule                                                            │
│  [ Weekly ▼ ]  on [ Sunday ▼ ]  at [ 02:00 ▼ ]                      │
│                                                                       │
│  Scan Limits                                                         │
│  Max pages: [ 500 ]    Max depth: [ 5 ]    Timeout: [ 15 ] sec      │
│                                                                       │
│  What to Check                                                       │
│  [✓] Check external links                                            │
│  [✓] Check image URLs                                                │
│                                                                       │
│  Exclusions                                                          │
│  Exclude paths (one per line):                                       │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │ /wp-admin/*                                                      ││
│  │ /feed/*                                                          ││
│  │ /cart/*                                                          ││
│  └─────────────────────────────────────────────────────────────────┘│
│                                                                       │
│  Exclude domains (one per line):                                     │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │ facebook.com                                                     ││
│  │ twitter.com                                                      ││
│  └─────────────────────────────────────────────────────────────────┘│
│                                                                       │
│  Alerts                                                              │
│  [✓] Alert when broken links exceed [ 5 ]                           │
│                                                                       │
│                                          [Cancel]  [Save Settings]  │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.4 Update Site Card

Show broken links count on site card:

```blade
{{-- Broken links indicator --}}
@if($site->linkMonitor && $site->linkMonitor->broken_links > 0)
    <div class="flex items-center gap-1 text-red-500" title="{{ $site->linkMonitor->broken_links }} broken links">
        <svg class="h-3.5 w-3.5"><!-- broken link icon --></svg>
        <span class="text-xs">{{ $site->linkMonitor->broken_links }}</span>
    </div>
@endif
```

### 6.5 Update Site Overview

Add links summary card:

```blade
<x-ui.card>
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500">Links</p>
            <p class="mt-1 text-lg font-semibold {{ $linkMonitor->broken_links > 0 ? 'text-red-600' : 'text-green-600' }}">
                {{ $linkMonitor->broken_links }} broken
            </p>
        </div>
        <div class="text-right text-sm text-gray-500">
            {{ $linkMonitor->total_links }} total<br>
            Scanned {{ $linkMonitor->last_scan_at?->diffForHumans() ?? 'never' }}
        </div>
    </div>
</x-ui.card>
```

---

## PART 7: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   └── SiteLinks.php                    # Main links page
│
├── Components/
│   ├── LinkScanProgress.php             # Scan progress card (polling)
│   ├── LinksTable.php                   # Filterable links table
│   ├── LinkRow.php                      # Single link row with actions
│   ├── LinkSettingsModal.php            # Settings modal
│   └── ScanHistoryTable.php             # Scan history
```

---

## PART 8: IMPLEMENTATION CHECKLIST

### Database & Models
- [ ] Create migration: link_monitors
- [ ] Create migration: link_scans
- [ ] Create migration: links
- [ ] Create model: LinkMonitor (with casts, relationships)
- [ ] Create model: LinkScan (with relationships)
- [ ] Create model: Link (with status helpers, colors)
- [ ] Add linkMonitor relationship to Site model

### Service & Jobs
- [ ] Create LinkCheckerService (crawl, extract links, check URLs)
- [ ] Create RunLinkScan job (orchestrates scan, updates progress, handles errors)
- [ ] Create NotifyBrokenLinks job (alert via existing channels)
- [ ] Add scheduler entry (hourly check for due scans)

### Auto-creation
- [ ] Auto-create link monitor when site is created
- [ ] Set sensible defaults (weekly, Sunday 02:00)

### UI Pages
- [ ] Build SiteLinks page with summary stats
- [ ] Build LinksTable with filters (All, Broken, Redirects, Timeouts, OK)
- [ ] Build search functionality (URL, anchor text)
- [ ] Build LinkRow with dismiss action and view link
- [ ] Build LinkScanProgress with real-time polling
- [ ] Build LinkSettingsModal (frequency, limits, exclusions, alerts)
- [ ] Build ScanHistoryTable
- [ ] "Scan Now" button with loading state

### Integration
- [ ] Wire broken links count to site card
- [ ] Wire links summary to site overview
- [ ] Connect alerts to existing notification channels
