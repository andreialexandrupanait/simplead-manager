This prompt adds improvements and new features to the already-implemented Performance Monitoring module. Do NOT rebuild what exists — extend it.

Reference `simplead-manager-architecture.md` for conventions.

---

## 1. Multi-Page Testing

Currently we only test the homepage. Allow testing multiple pages per site.

### Database changes:

Add a `performance_pages` table:

```php
Schema::create('performance_pages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('performance_monitor_id')->constrained()->onDelete('cascade');
    $table->string('label'); // "Homepage", "Shop", "Contact", "Blog"
    $table->string('url'); // full URL
    $table->boolean('is_primary')->default(false); // the main page shown on overview
    $table->timestamps();
});
```

Update `RunPerformanceTest` to loop through all pages (not just one URL). Store `performance_page_id` on `performance_tests` table (add nullable foreign key).

### UI:

On the Performance page, add a page selector above the scores:

```
Pages: [Homepage ●] [Shop] [Blog] [Contact]  [+ Add Page]
```

Clicking a page shows its scores. The primary page is shown by default and used for site card/overview scores. "Add Page" opens a small form: label + URL.

---

## 2. Third-Party Scripts Impact

Parse Lighthouse's `third-party-summary` audit to show which external scripts are slowing things down.

### Database:

Add to `performance_tests`:
```php
$table->json('third_party_scripts')->nullable();
```

### Parse from PageSpeed API:

In `PageSpeedService::parseResults()`, extract the `third-party-summary` audit:

```php
$thirdParty = [];
$tpAudit = $audits['third-party-summary']['details']['items'] ?? [];
foreach ($tpAudit as $item) {
    $thirdParty[] = [
        'entity' => $item['entity'] ?? 'Unknown',
        'transfer_size' => $item['transferSize'] ?? 0,
        'blocking_time' => $item['blockingTime'] ?? 0,
        'main_thread_time' => $item['mainThreadTime'] ?? 0,
    ];
}
// Sort by blocking time desc
usort($thirdParty, fn($a, $b) => $b['blocking_time'] <=> $a['blocking_time']);
```

### UI — New section on Performance page:

```
┌─ Third-Party Impact ───────────────────────────────────────────────┐
│                                                                     │
│  Script / Service          Size      Blocking    Main Thread       │
│  ──────────────────────────────────────────────────────────────── │
│  Google Analytics          45 KB     120 ms      180 ms            │
│  Facebook Pixel            38 KB     85 ms       140 ms            │
│  Google Fonts              62 KB     0 ms        25 ms             │
│  Hotjar                    28 KB     60 ms       95 ms             │
│  Cloudflare CDN            12 KB     0 ms        5 ms              │
│                                                                     │
│  Total: 185 KB transfer, 265 ms blocking time                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 3. DOM Size & Complexity

Parse `dom-size` audit from Lighthouse.

Add to `performance_tests`:
```php
$table->integer('dom_elements')->nullable(); // total DOM nodes
$table->integer('dom_max_depth')->nullable();
$table->integer('dom_max_children')->nullable();
```

Extract in service:
```php
$domAudit = $audits['dom-size'] ?? [];
$domItems = $domAudit['details']['items'] ?? [];
// items[0] = total elements, items[1] = max depth, items[2] = max children
```

Show as a small info card on the Performance page:
```
DOM: 1,245 elements  •  Max depth: 14  •  Max children: 85
```
Color: green if <800 elements, orange 800-1500, red >1500.

---

## 4. Unused CSS/JS Detection

Parse `unused-css-rules` and `unused-javascript` audits.

Add to `performance_tests`:
```php
$table->integer('unused_js_bytes')->nullable();
$table->integer('unused_css_bytes')->nullable();
$table->json('unused_js_details')->nullable(); // [{url, total, unused, percent}]
$table->json('unused_css_details')->nullable();
```

Extract from audits:
```php
// unused-javascript
$unusedJs = [];
$unusedJsTotal = 0;
foreach (($audits['unused-javascript']['details']['items'] ?? []) as $item) {
    $unusedJsTotal += $item['wastedBytes'] ?? 0;
    $unusedJs[] = [
        'url' => basename($item['url'] ?? ''),
        'total_bytes' => $item['totalBytes'] ?? 0,
        'wasted_bytes' => $item['wastedBytes'] ?? 0,
    ];
}
// Same for unused-css-rules
```

### UI section:

```
┌─ Unused Code ──────────────────────────────────────────────────────┐
│                                                                     │
│  Unused JavaScript: 320 KB (42% of total JS)                      │
│  ████████████████████░░░░░░░░░░░░░░  42%                          │
│                                                                     │
│  • woocommerce.min.js     — 145 KB unused of 280 KB               │
│  • analytics-bundle.js    — 95 KB unused of 120 KB                │
│  • theme-scripts.js       — 80 KB unused of 190 KB                │
│                                                                     │
│  Unused CSS: 85 KB (38% of total CSS)                              │
│  ██████████████░░░░░░░░░░░░░░░░░░░░  38%                          │
│                                                                     │
│  • style.css              — 52 KB unused of 140 KB                 │
│  • woocommerce.css        — 33 KB unused of 65 KB                 │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 5. Image Audit

Parse `uses-optimized-images`, `modern-image-formats`, `uses-responsive-images`, and `offscreen-images` audits.

Add to `performance_tests`:
```php
$table->json('image_audit')->nullable();
```

Structure:
```php
$imageAudit = [
    'unoptimized_count' => 0,
    'unoptimized_savings_bytes' => 0,
    'not_webp_count' => 0,
    'not_webp_savings_bytes' => 0,
    'oversized_count' => 0,
    'oversized_savings_bytes' => 0,
    'offscreen_count' => 0, // images not lazy loaded
    'offscreen_savings_bytes' => 0,
    'total_image_count' => 0,
    'issues' => [], // [{url, current_size, potential_size, type}]
];
```

### UI section:

```
┌─ Image Audit ──────────────────────────────────────────────────────┐
│                                                                     │
│  📷 18 images analyzed                                             │
│                                                                     │
│  ⚠ 5 not in next-gen format (WebP/AVIF)      Could save ~180 KB  │
│  ⚠ 3 oversized for their display size         Could save ~95 KB   │
│  ⚠ 4 offscreen images not lazy loaded         Could save ~120 KB  │
│  ✅ All images adequately compressed                                │
│                                                                     │
│  Total potential savings: ~395 KB                                  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 6. Performance Budget

Let users define performance limits and get alerts when exceeded.

Add to `performance_monitors`:
```php
$table->json('budgets')->nullable();
```

Structure:
```php
[
    'performance_score_min' => 80,
    'lcp_max' => 2.5,    // seconds
    'cls_max' => 0.1,
    'tbt_max' => 200,    // ms
    'fcp_max' => 1.8,    // seconds
    'js_max_kb' => 300,  // KB
    'css_max_kb' => 100,
    'image_max_kb' => 500,
    'total_max_kb' => 1500,
]
```

After each test, compare results against budgets. Show violations on the Performance page:

```
┌─ Budget Status ────────────────────────────────────────────────────┐
│                                                                     │
│  ✅ Performance Score   71 / min 60                                │
│  ❌ LCP                 6.3s / max 2.5s          ← OVER BUDGET    │
│  ✅ CLS                 0.001 / max 0.1                            │
│  ❌ JavaScript          540 KB / max 300 KB       ← OVER BUDGET   │
│  ✅ Total Page Weight   1.8 MB / max 1.5 MB                       │
│                                                                     │
│  2 of 5 budgets exceeded                              [Edit Budgets]│
└─────────────────────────────────────────────────────────────────────┘
```

Send alert notification when a budget is exceeded for the first time (don't repeat on every test if still exceeded — only alert again if it was OK and then exceeded again).

Add a "Edit Budgets" modal with fields for each metric limit.

---

## 7. Cross-Site Comparison

On the global Performance overview, show a ranking of all sites.

### New page: `/performance` (global, accessible from sidebar)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Performance Overview                                    [Test All]  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Summary ───────────────────────────────────────────────────────┐ │
│  │  12 sites monitored  •  Avg Mobile: 68  •  Avg Desktop: 89     │ │
│  │  3 sites scoring poor (<50)  •  5 budget violations             │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  Rank │ Site                │ Mobile │ Desktop │ LCP   │ Trend    │ │
│  ──────────────────────────────────────────────────────────────────  │
│  1    │ blog.simplead.ro    │ 95 🟢  │ 99 🟢  │ 1.2s  │ ↑ +3    │ │
│  2    │ shop.client.ro      │ 82 🟠  │ 96 🟢  │ 2.1s  │ → 0     │ │
│  3    │ simplead.ro         │ 71 🟠  │ 97 🟢  │ 6.3s  │ ↑ +3    │ │
│  4    │ client2.com         │ 65 🟠  │ 88 🟠  │ 3.8s  │ ↓ -5    │ │
│  ...                                                                 │
│  12   │ oldsite.ro          │ 32 🔴  │ 58 🟠  │ 8.2s  │ ↓ -12   │ │
└─────────────────────────────────────────────────────────────────────┘
```

Trend = difference from previous test. Sort by mobile score by default, allow sorting by any column.

---

## 8. WordPress Health Detection

After each performance test, cross-reference with the site's WordPress data (plugins list) to detect common performance issues.

Add to `performance_tests`:
```php
$table->json('wp_health_checks')->nullable();
```

Logic (run after test completes, compare with site's plugin list):

```php
$checks = [];

// Check for caching plugin
$hasCache = $site->plugins()->where('is_active', true)
    ->whereIn('slug', ['wp-super-cache', 'w3-total-cache', 'wp-fastest-cache', 'litespeed-cache', 'wp-rocket', 'breeze', 'hummingbird-performance'])
    ->exists();
$checks[] = ['id' => 'cache', 'label' => 'Page caching plugin', 'status' => $hasCache ? 'pass' : 'fail', 'recommendation' => $hasCache ? null : 'Install a caching plugin like LiteSpeed Cache or WP Rocket'];

// Check for image optimization
$hasImageOpt = $site->plugins()->where('is_active', true)
    ->whereIn('slug', ['imagify', 'wp-smushit', 'shortpixel-image-optimiser', 'ewww-image-optimizer', 'optimole-wp'])
    ->exists();
$checks[] = ['id' => 'images', 'label' => 'Image optimization plugin', 'status' => $hasImageOpt ? 'pass' : 'fail', 'recommendation' => $hasImageOpt ? null : 'Install an image optimization plugin like Imagify or ShortPixel'];

// Check for lazy loading (WP 5.5+ has native, but check for plugins too)
// Check for CDN (detect from third-party scripts: Cloudflare, StackPath, BunnyCDN)
// Check for minification plugin
// Check for too many active plugins (>25)
$pluginCount = $site->plugins()->where('is_active', true)->count();
$checks[] = ['id' => 'plugin_count', 'label' => "Active plugins ($pluginCount)", 'status' => $pluginCount <= 25 ? 'pass' : ($pluginCount <= 40 ? 'warn' : 'fail'), 'recommendation' => $pluginCount > 25 ? 'Consider deactivating unused plugins to improve performance' : null];

// Check for PHP version
$phpVersion = (float) $site->php_version;
$checks[] = ['id' => 'php', 'label' => "PHP version ($site->php_version)", 'status' => $phpVersion >= 8.1 ? 'pass' : ($phpVersion >= 8.0 ? 'warn' : 'fail'), 'recommendation' => $phpVersion < 8.1 ? 'Upgrade PHP to 8.1+ for better performance' : null];
```

### UI section:

```
┌─ WordPress Health ─────────────────────────────────────────────────┐
│                                                                     │
│  ✅ Page caching plugin           LiteSpeed Cache active           │
│  ✅ Image optimization            Imagify active                    │
│  ⚠️ Active plugins (28)           Consider reducing, 25+ may slow  │
│  ✅ PHP version (8.2)             Up to date                        │
│  ❌ No minification plugin        Install Autoptimize or similar   │
│  ✅ CDN detected                   Cloudflare                       │
│                                                                     │
│  4 of 6 checks passed                                               │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 9. Score History Improvements

Enhance the existing score history chart:

- Add **markers for events** on the chart timeline: plugin updates, core updates, backup restores. Pull dates from `update_logs` table. Show as small dots/icons on the timeline that you can hover for details: "WP Core updated to 6.5 • WooCommerce updated to 8.6"
- Add **comparison overlay** — let the user select 2 dates and see the full metric comparison side by side
- Show **rolling average** line in addition to actual scores (7-day rolling average smooths out variance)

---

## 10. Lighthouse Screenshots & Filmstrip

The PageSpeed API returns screenshots. Parse and store them.

Add to `performance_tests`:
```php
$table->text('screenshot_final')->nullable(); // base64 thumbnail of final rendered page
$table->json('filmstrip')->nullable(); // [{timing_ms, data_base64}] — loading sequence
```

Parse from API:
```php
// Final screenshot
$screenshot = $audits['final-screenshot']['details']['data'] ?? null;

// Filmstrip (screenshot thumbnails at intervals)
$filmstrip = [];
$filmstripAudit = $audits['screenshot-thumbnails']['details']['items'] ?? [];
foreach ($filmstripAudit as $frame) {
    $filmstrip[] = [
        'timing' => $frame['timing'] ?? 0,
        'data' => $frame['data'] ?? null,
    ];
}
```

### UI — Filmstrip section:

```
┌─ Loading Filmstrip ────────────────────────────────────────────────┐
│                                                                     │
│  0.0s    0.5s    1.0s    1.5s    2.0s    2.5s    3.0s    3.5s    │
│  ┌────┐  ┌────┐  ┌────┐  ┌────┐  ┌────┐  ┌────┐  ┌────┐  ┌────┐│
│  │    │  │    │  │░░░░│  │▓▓▓▓│  │▓▓▓▓│  │████│  │████│  │████││
│  │    │  │    │  │░░░░│  │▓▓▓▓│  │▓▓▓▓│  │████│  │████│  │████││
│  └────┘  └────┘  └────┘  └────┘  └────┘  └────┘  └────┘  └────┘│
│  blank   blank   FCP     loading  LCP     loaded  done    done   │
└─────────────────────────────────────────────────────────────────────┘
```

Show the filmstrip as a horizontal row of small thumbnails with timestamps. Mark FCP and LCP moments with labels. This gives a visual sense of how the page loads.

---

## Summary — add all of the above to the existing Performance module:

1. Multi-page testing (performance_pages table, page selector tabs)
2. Third-party scripts impact table
3. DOM size & complexity metrics
4. Unused CSS/JS detection with file-level detail
5. Image audit (format, sizing, lazy loading, optimization)
6. Performance budgets (user-defined limits + alerts + violation display)
7. Global cross-site performance comparison page at `/performance`
8. WordPress health detection (cache, CDN, image plugin, PHP version, plugin count)
9. Score history enhancements (event markers from update_logs, rolling average)
10. Lighthouse screenshots & filmstrip visualization

Work autonomously. Extend the existing models, service, job, and UI — do not rebuild from scratch.
