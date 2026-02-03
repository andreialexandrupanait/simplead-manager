# SimpleAd Manager — Feature Spec: PDF Reports

---

## Overview

Generate professional PDF reports for clients showing site health, performance, uptime, backups, updates, analytics, and search console data. Supports scheduling (weekly, monthly), email delivery, custom branding (client logo), and manual on-demand generation.

The report structure is based on the WPMUDEV-style maintenance report with 12-13 pages covering all monitored aspects.

---

## PART 1: DATABASE SCHEMA

### Migration: `report_templates`

Reusable report templates:

```php
Schema::create('report_templates', function (Blueprint $table) {
    $table->id();
    
    $table->string('name'); // "Monthly Maintenance Report", "Performance Report"
    $table->text('description')->nullable();
    
    // What to include (sections)
    $table->json('sections'); // ["overview", "updates", "uptime", "backups", "analytics", "search_console", "performance", "links"]
    
    // Branding
    $table->string('company_name')->nullable(); // Your company name (SimpleAd)
    $table->string('company_logo_path')->nullable(); // Path to logo file
    $table->string('company_website')->nullable();
    $table->string('primary_color')->default('#7C3AED'); // Purple/violet
    
    // Content customization
    $table->text('intro_text')->nullable(); // Custom intro paragraph
    $table->text('closing_text')->nullable(); // Thank you message
    
    $table->boolean('is_default')->default(false);
    
    $table->timestamps();
});
```

### Migration: `report_schedules`

Per-site report scheduling:

```php
Schema::create('report_schedules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('report_template_id')->constrained()->onDelete('cascade');
    
    $table->boolean('is_active')->default(true);
    
    // Schedule
    $table->string('frequency'); // weekly, monthly
    $table->integer('day_of_week')->nullable(); // 0-6 for weekly (0=Sunday)
    $table->integer('day_of_month')->nullable(); // 1-28 for monthly
    $table->string('time')->default('08:00'); // HH:MM
    $table->string('timezone')->default('Europe/Bucharest');
    
    // Report period
    $table->string('period'); // last_7_days, last_30_days, last_month, custom
    
    // Delivery
    $table->json('recipient_emails'); // ["client@example.com", "manager@company.com"]
    $table->boolean('send_copy_to_admin')->default(true);
    $table->string('email_subject')->nullable(); // Custom subject, null = default
    $table->text('email_body')->nullable(); // Custom email body
    
    // Client branding (overrides template)
    $table->string('client_name')->nullable();
    $table->string('client_logo_path')->nullable();
    
    // Tracking
    $table->timestamp('last_generated_at')->nullable();
    $table->timestamp('last_sent_at')->nullable();
    $table->timestamp('next_run_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['is_active', 'next_run_at']);
});
```

### Migration: `reports`

Generated report history:

```php
Schema::create('reports', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('report_template_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
    
    $table->string('title');
    $table->date('period_start');
    $table->date('period_end');
    
    // Generated file
    $table->string('file_path')->nullable(); // Path to PDF
    $table->string('file_name')->nullable();
    $table->integer('file_size')->nullable(); // bytes
    $table->integer('page_count')->nullable();
    
    // Status
    $table->string('status')->default('pending'); // pending, generating, completed, failed
    $table->text('error_message')->nullable();
    
    // Trigger
    $table->string('trigger'); // scheduled, manual
    
    // Delivery tracking
    $table->boolean('was_sent')->default(false);
    $table->timestamp('sent_at')->nullable();
    $table->json('sent_to')->nullable(); // emails it was sent to
    
    // Cached data snapshot (for regeneration if needed)
    $table->json('data_snapshot')->nullable();
    
    $table->timestamp('generated_at')->nullable();
    $table->timestamps();
    
    $table->index(['site_id', 'created_at']);
});
```

---

## PART 2: REPORT STRUCTURE (Pages)

The PDF report follows this structure (12-13 pages):

### Page 1: Cover
- Company logo (or client logo if white-label)
- Report title: "Monthly Maintenance & Performance Report"
- Site name and URL
- Report period (e.g., "January 2026")
- Generated date

### Page 2: Introduction
- Brief intro text explaining the report
- What's included
- Contact information

### Page 3: Overview / Executive Summary
Quick summary cards for:
- Updates: "5 plugins updated, WordPress core up to date"
- Uptime: "99.95% uptime, 2 incidents"
- Backups: "12 backups completed, daily schedule"
- Performance: "Mobile: 71, Desktop: 97"
- Traffic: "1,245 users, 4,521 pageviews"
- Search: "56 clicks, 1.3K impressions"

### Page 4: Updates
- WordPress core version and update status
- Plugin updates table (plugin name, from version, to version, date)
- Theme updates table
- Summary: total updates performed

### Page 5: Uptime
- Uptime percentage for the period
- Average response time
- Response time chart (line graph)
- Incidents table (date, duration, cause)

### Page 6: Backups
- Backup status (enabled/disabled)
- Schedule (daily at 03:00)
- Backups completed in period
- Storage used
- Last backup date and size

### Page 7-8: Google Analytics
Page 7:
- Overview metrics (users, sessions, pageviews, bounce rate)
- Users over time chart
- New vs returning users pie chart
- Device breakdown

Page 8:
- Traffic sources breakdown
- Top 10 pages
- Top 5 countries
- Top 5 cities

### Page 9-10: Search Console
Page 9:
- Overview metrics (clicks, impressions, CTR, position)
- Performance over time chart

Page 10:
- Top 10 search queries table
- Top 5 pages table
- Countries breakdown
- Devices breakdown

### Page 11: Performance
- Mobile score (circular gauge) + Core Web Vitals
- Desktop score (circular gauge) + Core Web Vitals
- Performance comparison vs previous period (if available)
- Top recommendations

### Page 12: Broken Links (optional, if enabled)
- Total links checked
- Broken links found
- List of broken links with source page

### Page 13: Thank You / Footer
- Closing message
- Company contact info
- Company logo
- "Report generated by SimpleAd Manager"

---

## PART 3: PDF GENERATION SERVICE

Use **DomPDF** or **Browsershot** (Puppeteer) for PDF generation. DomPDF is simpler, Browsershot produces better quality.

### Install DomPDF

```bash
composer require barryvdh/laravel-dompdf
```

### Report Generator Service

```php
// app/Services/ReportGeneratorService.php

use Barryvdh\DomPDF\Facade\Pdf;

class ReportGeneratorService
{
    private Site $site;
    private ReportTemplate $template;
    private Carbon $periodStart;
    private Carbon $periodEnd;
    private array $data = [];

    public function __construct(Site $site, ReportTemplate $template, Carbon $periodStart, Carbon $periodEnd)
    {
        $this->site = $site;
        $this->template = $template;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
    }

    public function generate(): string
    {
        // Gather all data
        $this->gatherData();

        // Generate PDF
        $pdf = Pdf::loadView('reports.maintenance-report', [
            'site' => $this->site,
            'template' => $this->template,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'data' => $this->data,
        ]);

        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
        ]);

        // Save to storage
        $fileName = Str::slug($this->site->name) . '_report_' . $this->periodStart->format('Y-m') . '.pdf';
        $filePath = "reports/{$this->site->id}/{$fileName}";

        Storage::disk('local')->put($filePath, $pdf->output());

        return $filePath;
    }

    private function gatherData(): void
    {
        $sections = $this->template->sections;

        if (in_array('overview', $sections)) {
            $this->data['overview'] = $this->gatherOverviewData();
        }

        if (in_array('updates', $sections)) {
            $this->data['updates'] = $this->gatherUpdatesData();
        }

        if (in_array('uptime', $sections)) {
            $this->data['uptime'] = $this->gatherUptimeData();
        }

        if (in_array('backups', $sections)) {
            $this->data['backups'] = $this->gatherBackupsData();
        }

        if (in_array('analytics', $sections)) {
            $this->data['analytics'] = $this->gatherAnalyticsData();
        }

        if (in_array('search_console', $sections)) {
            $this->data['search_console'] = $this->gatherSearchConsoleData();
        }

        if (in_array('performance', $sections)) {
            $this->data['performance'] = $this->gatherPerformanceData();
        }

        if (in_array('links', $sections)) {
            $this->data['links'] = $this->gatherLinksData();
        }
    }

    private function gatherOverviewData(): array
    {
        return [
            'updates_count' => UpdateLog::where('site_id', $this->site->id)
                ->whereBetween('performed_at', [$this->periodStart, $this->periodEnd])
                ->count(),
            'uptime_percentage' => $this->site->uptimeMonitor?->uptime_30d ?? 100,
            'incidents_count' => UptimeIncident::where('site_id', $this->site->id)
                ->whereBetween('started_at', [$this->periodStart, $this->periodEnd])
                ->count(),
            'backups_count' => Backup::where('site_id', $this->site->id)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
                ->count(),
            'performance_mobile' => $this->site->performanceMonitor?->latest_mobile_score,
            'performance_desktop' => $this->site->performanceMonitor?->latest_desktop_score,
            'analytics_users' => $this->getAnalyticsCached('overview')['total_users'] ?? 0,
            'analytics_pageviews' => $this->getAnalyticsCached('overview')['pageviews'] ?? 0,
            'search_clicks' => $this->getSearchConsoleCached('overview')['clicks'] ?? 0,
            'search_impressions' => $this->getSearchConsoleCached('overview')['impressions'] ?? 0,
        ];
    }

    private function gatherUpdatesData(): array
    {
        $updates = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('performed_at', 'desc')
            ->get();

        return [
            'wordpress_version' => $this->site->wp_version,
            'core_updates' => $updates->where('type', 'core')->values(),
            'plugin_updates' => $updates->where('type', 'plugin')->values(),
            'theme_updates' => $updates->where('type', 'theme')->values(),
            'total_count' => $updates->count(),
        ];
    }

    private function gatherUptimeData(): array
    {
        $monitor = $this->site->uptimeMonitor;
        if (!$monitor) return ['enabled' => false];

        $incidents = UptimeIncident::where('uptime_monitor_id', $monitor->id)
            ->whereBetween('started_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('started_at', 'desc')
            ->get();

        // Get response time data for chart
        $responseTimeData = UptimeCheck::where('uptime_monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$this->periodStart, $this->periodEnd])
            ->selectRaw('DATE(checked_at) as date, AVG(response_time) as avg_response_time')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'enabled' => true,
            'uptime_percentage' => $monitor->uptime_30d ?? 100,
            'avg_response_time' => $monitor->avg_response_time,
            'incidents' => $incidents,
            'incidents_count' => $incidents->count(),
            'total_downtime_minutes' => $incidents->sum('duration_seconds') / 60,
            'response_time_chart' => $responseTimeData,
        ];
    }

    private function gatherBackupsData(): array
    {
        $config = $this->site->backupConfig;
        $backups = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'enabled' => (bool) $config?->is_enabled,
            'frequency' => $config?->frequency ?? 'Not configured',
            'backups_count' => $backups->count(),
            'total_size' => $backups->sum('file_size'),
            'last_backup' => $backups->first(),
            'storage_destination' => $config?->storageDestination?->name ?? 'N/A',
        ];
    }

    private function gatherAnalyticsData(): array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->first();

        if (!$cache) return ['enabled' => false];

        return [
            'enabled' => true,
            'overview' => $cache->data['overview'] ?? [],
            'users_over_time' => $cache->data['users_over_time'] ?? [],
            'traffic_sources' => $cache->data['traffic_sources'] ?? [],
            'top_pages' => array_slice($cache->data['top_pages'] ?? [], 0, 10),
            'devices' => $cache->data['devices'] ?? [],
            'countries' => array_slice($cache->data['countries'] ?? [], 0, 5),
            'cities' => array_slice($cache->data['cities'] ?? [], 0, 5),
        ];
    }

    private function gatherSearchConsoleData(): array
    {
        // Similar structure for Search Console cached data
        $overviewCache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'overview')
            ->first();

        if (!$overviewCache) return ['enabled' => false];

        return [
            'enabled' => true,
            'overview' => $overviewCache->data ?? [],
            'performance_over_time' => $this->getSearchConsoleCached('performance_over_time'),
            'queries' => array_slice($this->getSearchConsoleCached('queries'), 0, 10),
            'pages' => array_slice($this->getSearchConsoleCached('pages'), 0, 5),
            'countries' => array_slice($this->getSearchConsoleCached('countries'), 0, 5),
            'devices' => $this->getSearchConsoleCached('devices'),
        ];
    }

    private function gatherPerformanceData(): array
    {
        $monitor = $this->site->performanceMonitor;
        if (!$monitor) return ['enabled' => false];

        $latestMobile = $monitor->latestMobileTest;
        $latestDesktop = $monitor->latestDesktopTest;

        return [
            'enabled' => true,
            'mobile' => [
                'score' => $latestMobile?->performance_score,
                'fcp' => $latestMobile?->fcp,
                'lcp' => $latestMobile?->lcp,
                'cls' => $latestMobile?->cls,
                'tbt' => $latestMobile?->tbt,
                'si' => $latestMobile?->si,
            ],
            'desktop' => [
                'score' => $latestDesktop?->performance_score,
                'fcp' => $latestDesktop?->fcp,
                'lcp' => $latestDesktop?->lcp,
                'cls' => $latestDesktop?->cls,
                'tbt' => $latestDesktop?->tbt,
                'si' => $latestDesktop?->si,
            ],
            'opportunities' => array_slice($latestMobile?->opportunities ?? [], 0, 5),
            'tested_at' => $monitor->last_tested_at,
        ];
    }

    private function gatherLinksData(): array
    {
        $monitor = $this->site->linkMonitor;
        if (!$monitor) return ['enabled' => false];

        $latestScan = $monitor->latestCompletedScan;
        if (!$latestScan) return ['enabled' => true, 'has_scan' => false];

        $brokenLinks = Link::where('link_scan_id', $latestScan->id)
            ->whereIn('status', ['broken', 'timeout', 'ssl_error', 'dns_error'])
            ->where('is_dismissed', false)
            ->limit(20)
            ->get();

        return [
            'enabled' => true,
            'has_scan' => true,
            'total_links' => $latestScan->links_checked,
            'broken_count' => $latestScan->broken_count,
            'broken_links' => $brokenLinks,
            'scanned_at' => $latestScan->completed_at,
        ];
    }

    private function getAnalyticsCached(string $key): array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->first();

        return $cache?->data[$key] ?? [];
    }

    private function getSearchConsoleCached(string $dataType): array
    {
        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', $dataType)
            ->first();

        return $cache?->data ?? [];
    }
}
```

---

## PART 4: BLADE TEMPLATES FOR PDF

Create detailed Blade templates for the PDF:

```
resources/views/reports/
├── maintenance-report.blade.php    # Main wrapper
├── partials/
│   ├── cover.blade.php             # Page 1: Cover
│   ├── intro.blade.php             # Page 2: Introduction
│   ├── overview.blade.php          # Page 3: Executive Summary
│   ├── updates.blade.php           # Page 4: Updates
│   ├── uptime.blade.php            # Page 5: Uptime
│   ├── backups.blade.php           # Page 6: Backups
│   ├── analytics-1.blade.php       # Page 7: Analytics overview
│   ├── analytics-2.blade.php       # Page 8: Analytics details
│   ├── search-console-1.blade.php  # Page 9: Search Console overview
│   ├── search-console-2.blade.php  # Page 10: Search Console details
│   ├── performance.blade.php       # Page 11: Performance
│   ├── links.blade.php             # Page 12: Broken Links
│   └── footer.blade.php            # Page 13: Thank You
└── styles.blade.php                # Inline CSS for PDF
```

### Main Template Structure

```blade
{{-- resources/views/reports/maintenance-report.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $site->name }} - Maintenance Report</title>
    @include('reports.styles')
</head>
<body>
    {{-- Cover Page --}}
    @include('reports.partials.cover')

    {{-- Introduction --}}
    @include('reports.partials.intro')

    {{-- Overview / Executive Summary --}}
    @if(in_array('overview', $template->sections))
        @include('reports.partials.overview')
    @endif

    {{-- Updates --}}
    @if(in_array('updates', $template->sections) && !empty($data['updates']))
        @include('reports.partials.updates')
    @endif

    {{-- Uptime --}}
    @if(in_array('uptime', $template->sections) && ($data['uptime']['enabled'] ?? false))
        @include('reports.partials.uptime')
    @endif

    {{-- Backups --}}
    @if(in_array('backups', $template->sections))
        @include('reports.partials.backups')
    @endif

    {{-- Analytics --}}
    @if(in_array('analytics', $template->sections) && ($data['analytics']['enabled'] ?? false))
        @include('reports.partials.analytics-1')
        @include('reports.partials.analytics-2')
    @endif

    {{-- Search Console --}}
    @if(in_array('search_console', $template->sections) && ($data['search_console']['enabled'] ?? false))
        @include('reports.partials.search-console-1')
        @include('reports.partials.search-console-2')
    @endif

    {{-- Performance --}}
    @if(in_array('performance', $template->sections) && ($data['performance']['enabled'] ?? false))
        @include('reports.partials.performance')
    @endif

    {{-- Broken Links --}}
    @if(in_array('links', $template->sections) && ($data['links']['enabled'] ?? false) && ($data['links']['broken_count'] ?? 0) > 0)
        @include('reports.partials.links')
    @endif

    {{-- Footer / Thank You --}}
    @include('reports.partials.footer')
</body>
</html>
```

### Sample Page Template (Cover)

```blade
{{-- resources/views/reports/partials/cover.blade.php --}}
<div class="page cover-page">
    <div class="cover-content">
        @if($template->company_logo_path)
            <img src="{{ storage_path('app/' . $template->company_logo_path) }}" alt="Logo" class="cover-logo">
        @else
            <h1 class="cover-company">{{ $template->company_name ?? 'SimpleAd Manager' }}</h1>
        @endif

        <div class="cover-title">
            <h2>Raport Lunar de</h2>
            <h1>Mentenanță și Performanță</h1>
        </div>

        <div class="cover-site">
            <h3>{{ $site->name }}</h3>
            <p>{{ $site->url }}</p>
        </div>

        <div class="cover-period">
            <p>Perioada: {{ $period_start->format('d M') }} - {{ $period_end->format('d M Y') }}</p>
        </div>

        <div class="cover-date">
            <p>Generat: {{ now()->format('d M Y, H:i') }}</p>
        </div>
    </div>
</div>
```

### PDF Styles

```blade
{{-- resources/views/reports/styles.blade.php --}}
<style>
    @page {
        margin: 20mm 15mm;
        size: A4 portrait;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10pt;
        line-height: 1.5;
        color: #1f2937;
    }

    .page {
        page-break-after: always;
    }

    .page:last-child {
        page-break-after: auto;
    }

    /* Cover page */
    .cover-page {
        text-align: center;
        padding-top: 100px;
    }

    .cover-logo {
        max-width: 200px;
        margin-bottom: 60px;
    }

    .cover-company {
        font-size: 24pt;
        color: {{ $template->primary_color ?? '#7C3AED' }};
        margin-bottom: 60px;
    }

    .cover-title h1 {
        font-size: 28pt;
        color: #111827;
        margin: 0;
    }

    .cover-title h2 {
        font-size: 14pt;
        color: #6b7280;
        font-weight: normal;
        margin: 0 0 10px 0;
    }

    .cover-site {
        margin-top: 60px;
    }

    .cover-site h3 {
        font-size: 18pt;
        color: #111827;
        margin: 0;
    }

    .cover-site p {
        color: #6b7280;
    }

    /* Section headers */
    .section-header {
        background: {{ $template->primary_color ?? '#7C3AED' }};
        color: white;
        padding: 15px 20px;
        margin: -20px -15px 20px -15px;
        font-size: 16pt;
    }

    /* Metric cards */
    .metrics-grid {
        display: table;
        width: 100%;
        margin-bottom: 20px;
    }

    .metric-card {
        display: table-cell;
        width: 25%;
        padding: 15px;
        text-align: center;
        border: 1px solid #e5e7eb;
        background: #f9fafb;
    }

    .metric-value {
        font-size: 24pt;
        font-weight: bold;
        color: {{ $template->primary_color ?? '#7C3AED' }};
    }

    .metric-label {
        font-size: 9pt;
        color: #6b7280;
        text-transform: uppercase;
    }

    /* Tables */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }

    th, td {
        padding: 8px 12px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }

    th {
        background: #f3f4f6;
        font-weight: 600;
        font-size: 9pt;
        text-transform: uppercase;
        color: #6b7280;
    }

    /* Score gauges (simplified for PDF) */
    .score-box {
        display: inline-block;
        width: 120px;
        height: 120px;
        border-radius: 50%;
        text-align: center;
        line-height: 120px;
        font-size: 36pt;
        font-weight: bold;
        margin: 20px;
    }

    .score-good { background: #dcfce7; color: #16a34a; }
    .score-moderate { background: #fef3c7; color: #d97706; }
    .score-poor { background: #fee2e2; color: #dc2626; }

    /* Status indicators */
    .status-up { color: #16a34a; }
    .status-down { color: #dc2626; }
    .status-warning { color: #d97706; }

    /* Footer */
    .page-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 8pt;
        color: #9ca3af;
        padding: 10px;
    }
</style>
```

---

## PART 5: GENERATE REPORT JOB

```php
// app/Jobs/GenerateReport.php

class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes

    public function __construct(
        public Site $site,
        public ReportTemplate $template,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $trigger = 'manual',
        public ?ReportSchedule $schedule = null,
        public ?array $recipientEmails = null
    ) {}

    public function handle(): void
    {
        // Create report record
        $report = Report::create([
            'site_id' => $this->site->id,
            'report_template_id' => $this->template->id,
            'report_schedule_id' => $this->schedule?->id,
            'title' => $this->site->name . ' - ' . $this->template->name,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'status' => 'generating',
            'trigger' => $this->trigger,
        ]);

        try {
            // Generate PDF
            $generator = new ReportGeneratorService(
                $this->site,
                $this->template,
                $this->periodStart,
                $this->periodEnd
            );

            $filePath = $generator->generate();
            $fileSize = Storage::disk('local')->size($filePath);

            // Update report record
            $report->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => basename($filePath),
                'file_size' => $fileSize,
                'generated_at' => now(),
            ]);

            // Send email if recipients provided
            if ($this->recipientEmails) {
                $this->sendReportEmail($report);
            }

            // Update schedule if this was scheduled
            if ($this->schedule) {
                $this->schedule->update([
                    'last_generated_at' => now(),
                    'last_sent_at' => $this->recipientEmails ? now() : null,
                    'next_run_at' => $this->calculateNextRun(),
                ]);
            }

        } catch (\Exception $e) {
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function sendReportEmail(Report $report): void
    {
        foreach ($this->recipientEmails as $email) {
            Mail::to($email)->send(new ReportGeneratedMail($report, $this->site, $this->schedule));
        }

        $report->update([
            'was_sent' => true,
            'sent_at' => now(),
            'sent_to' => $this->recipientEmails,
        ]);
    }

    private function calculateNextRun(): Carbon
    {
        if (!$this->schedule) return now()->addYear();

        return match($this->schedule->frequency) {
            'weekly' => now()->addWeek()->startOfWeek()->addDays($this->schedule->day_of_week ?? 0)->setTimeFromTimeString($this->schedule->time),
            'monthly' => now()->addMonth()->startOfMonth()->addDays(($this->schedule->day_of_month ?? 1) - 1)->setTimeFromTimeString($this->schedule->time),
            default => now()->addMonth(),
        };
    }
}
```

### Report Email Mailable

```php
// app/Mail/ReportGeneratedMail.php

class ReportGeneratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Report $report,
        public Site $site,
        public ?ReportSchedule $schedule
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->schedule?->email_subject 
            ?? "Raport {$this->site->name} - {$this->report->period_start->format('M Y')}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.report-generated',
            with: [
                'site' => $this->site,
                'report' => $this->report,
                'customBody' => $this->schedule?->email_body,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorage($this->report->file_path)
                ->as($this->report->file_name)
                ->withMime('application/pdf'),
        ];
    }
}
```

### Scheduler

```php
// Check for scheduled reports every hour
Schedule::call(function () {
    ReportSchedule::where('is_active', true)
        ->where(function ($q) {
            $q->whereNull('next_run_at')
              ->orWhere('next_run_at', '<=', now());
        })
        ->with(['site', 'reportTemplate'])
        ->each(function ($schedule) {
            [$periodStart, $periodEnd] = match($schedule->period) {
                'last_7_days' => [now()->subDays(7), now()->subDay()],
                'last_30_days' => [now()->subDays(30), now()->subDay()],
                'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                default => [now()->subDays(30), now()->subDay()],
            };

            GenerateReport::dispatch(
                $schedule->site,
                $schedule->reportTemplate,
                $periodStart,
                $periodEnd,
                'scheduled',
                $schedule,
                $schedule->recipient_emails
            );
        });
})->hourly();
```

---

## PART 6: UI PAGES

### 6.1 Reports Page — Site Context (`/sites/{site}/reports`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Reports — simplead.ro                          [Generate Report ▼]  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Schedule ──────────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Status: ● Active — Monthly on the 1st at 08:00                │ │
│  │  Template: Monthly Maintenance Report                           │ │
│  │  Recipients: client@example.com, andrei@simplead.ro            │ │
│  │  Next report: Feb 1, 2026 at 08:00                  [Configure]│ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Report History ────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Date          │ Period        │ Template     │ Size  │ Actions│ │
│  │ ───────────────────────────────────────────────────────────────  │ │
│  │  Jan 1, 08:00  │ Dec 2025      │ Monthly      │ 2.4 MB│ 📥 📧 👁│ │
│  │  Dec 1, 08:00  │ Nov 2025      │ Monthly      │ 2.2 MB│ 📥 📧 👁│ │
│  │  Nov 15, 14:30 │ Custom        │ Performance  │ 0.8 MB│ 📥 📧 👁│ │
│  │  Nov 1, 08:00  │ Oct 2025      │ Monthly      │ 2.1 MB│ 📥 📧 👁│ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  Actions: 📥 Download  📧 Send Email  👁 Preview                    │
│                                                                       │
│  No schedule configured? [Set Up Report Schedule]                    │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.2 Generate Report Modal

```
┌─────────────────────────────────────────────────────────────────────┐
│  Generate Report                                             [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Template                                                            │
│  [ Monthly Maintenance Report ▼ ]                                   │
│                                                                       │
│  Report Period                                                       │
│  ( ● Last 30 days  ( ○ Last month  ( ○ Custom                      │
│                                                                       │
│  Custom dates (if selected):                                         │
│  From: [ 2025-12-01 ]  To: [ 2025-12-31 ]                          │
│                                                                       │
│  Send to (optional):                                                 │
│  [ client@example.com, manager@company.com                       ] │
│  Separate multiple emails with commas                               │
│                                                                       │
│                              [Cancel]  [Generate & Download]         │
│                              [Generate & Send Email]                 │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.3 Schedule Configuration Modal

```
┌─────────────────────────────────────────────────────────────────────┐
│  Report Schedule                                             [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  [✓] Enable scheduled reports                                        │
│                                                                       │
│  Template                                                            │
│  [ Monthly Maintenance Report ▼ ]                                   │
│                                                                       │
│  Frequency                                                           │
│  ( ○ Weekly   ( ● Monthly                                           │
│                                                                       │
│  Day of month: [ 1 ▼ ]   Time: [ 08:00 ▼ ]                         │
│                                                                       │
│  Report Period                                                       │
│  [ Previous month ▼ ]                                               │
│                                                                       │
│  Recipients                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │ client@example.com                                              ││
│  │ manager@company.com                                             ││
│  └─────────────────────────────────────────────────────────────────┘│
│  [+ Add recipient]                                                   │
│                                                                       │
│  [✓] Send copy to admin (andrei@simplead.ro)                        │
│                                                                       │
│  Client Branding (optional)                                          │
│  Client name: [ ___________________________ ]                        │
│  Client logo: [ Choose file ] No file chosen                        │
│                                                                       │
│  Custom Email                                                        │
│  Subject: [ Raport lunar {site_name} - {month} ]                    │
│  Body: (optional custom message)                                     │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │                                                                  ││
│  └─────────────────────────────────────────────────────────────────┘│
│                                                                       │
│                                          [Cancel]  [Save Schedule]  │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.4 Report Templates Page (`/settings/report-templates`)

Global report templates management:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Report Templates                                    [+ New Template]│
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  📄 Monthly Maintenance Report                         Default  │ │
│  │  Full report with all sections: overview, updates, uptime,     │ │
│  │  backups, analytics, search console, performance               │ │
│  │  Used by: 8 sites                         [Edit] [Duplicate]   │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  📄 Performance Report                                          │ │
│  │  Focus on performance and Core Web Vitals                       │ │
│  │  Sections: overview, performance                                │ │
│  │  Used by: 2 sites                         [Edit] [Duplicate]   │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  📄 SEO Report                                                  │ │
│  │  Search Console and Analytics focus                             │ │
│  │  Sections: overview, analytics, search_console, performance    │ │
│  │  Used by: 3 sites                         [Edit] [Duplicate]   │ │
│  └─────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

---

## PART 7: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   └── SiteReports.php                  # Reports page with history
│
├── Settings/
│   └── ReportTemplatesSettings.php      # Templates management
│
├── Components/
│   ├── ReportHistoryTable.php           # Reports list with actions
│   ├── GenerateReportModal.php          # Manual generation form
│   ├── ReportScheduleForm.php           # Schedule configuration
│   ├── ReportTemplateForm.php           # Create/edit template
│   └── ReportPreview.php                # PDF preview (iframe)
```

---

## PART 8: IMPLEMENTATION CHECKLIST

### Database & Models
- [ ] Create migration: report_templates
- [ ] Create migration: report_schedules  
- [ ] Create migration: reports
- [ ] Create model: ReportTemplate
- [ ] Create model: ReportSchedule
- [ ] Create model: Report
- [ ] Add relationships to Site model

### PDF Generation
- [ ] Install DomPDF (`composer require barryvdh/laravel-dompdf`)
- [ ] Create ReportGeneratorService
- [ ] Create all Blade templates for PDF pages
- [ ] Create PDF styles (inline CSS)
- [ ] Test PDF generation with sample data

### Jobs & Email
- [ ] Create GenerateReport job
- [ ] Create ReportGeneratedMail mailable
- [ ] Create email template (Markdown)
- [ ] Add scheduler entry (hourly check for due reports)

### Default Template
- [ ] Create seeder for default "Monthly Maintenance Report" template
- [ ] Include all sections by default

### UI Pages
- [ ] Build SiteReports page (schedule status, report history)
- [ ] Build ReportHistoryTable with download/send/preview actions
- [ ] Build GenerateReportModal (template, period, recipients)
- [ ] Build ReportScheduleForm (frequency, day, time, recipients, branding)
- [ ] Build ReportTemplatesSettings page (list, create, edit, duplicate)
- [ ] Build ReportTemplateForm (name, sections checkboxes, branding)
- [ ] Download action (stream PDF)
- [ ] Send email action (resend existing report)
- [ ] Preview action (open PDF in new tab or iframe)

### Integration
- [ ] Show next report date on site overview
- [ ] Show last report date on site card (optional)
