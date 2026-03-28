<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\GenerateReport;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\AnalyticsCache;
use App\Models\Backup;
use App\Models\DatabaseHealthCheck;
use App\Models\ReportRecommendation;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\SearchConsoleCache;
use App\Models\SecurityRecommendation;
use App\Models\Site;
use App\Models\SiteCloudflare;
use App\Models\SitePlugin;
use App\Models\SiteUser;
use App\Models\UpdateLog;
use App\Services\ReportManagementService;
use App\Services\ReportRecommendationService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class SiteReports extends Component
{
    use WithJobTracking, WithPagination, WithSiteAuthorization;

    public Site $site;

    public ?int $selectedTemplateId = null;

    // Schedule modal
    public bool $showScheduleModal = false;

    public ?int $editingScheduleId = null;

    public bool $scheduleActive = true;

    public ?int $scheduleTemplateId = null;

    public string $scheduleFrequency = 'monthly';

    public int $scheduleDayOfWeek = 1;

    public int $scheduleDayOfMonth = 1;

    public string $scheduleTime = '08:00';

    public string $scheduleTimezone = 'Europe/Bucharest';

    public string $schedulePeriod = 'last_30_days';

    public string $scheduleRecipientEmails = '';

    public bool $scheduleSendCopyToAdmin = true;

    public string $scheduleEmailSubject = '';

    public string $scheduleEmailBody = '';

    public string $scheduleClientName = '';

    // Send modal
    public bool $showSendModal = false;

    public ?int $sendReportId = null;

    public string $sendToEmail = '';

    // Bulk actions
    public string $bulkSendEmail = '';

    // Generate modal
    public bool $showGenerateModal = false;

    public int $generateStep = 1;

    public array $sectionPreviews = [];

    public array $excludedSections = [];

    public $draftRecommendations = [];

    public string $newRecTitle = '';

    public string $newRecDescription = '';

    public string $newRecPriority = 'medium';

    public string $newRecCategory = 'technical';

    public bool $loadingRecommendations = false;

    protected function jobTrackingKeys(): array
    {
        return [
            'generate' => 'report-generate-'.$this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();

        $defaultTemplate = ReportTemplate::where('is_default', true)->first()
            ?? ReportTemplate::first();
        $this->selectedTemplateId = $defaultTemplate?->id;
        $this->scheduleTemplateId = $defaultTemplate?->id;
    }

    // ─── Generate Report Modal Flow ─────────────────────────────────

    public function openGenerateModal(): void
    {
        $this->generateStep = 1;
        $this->excludedSections = [];
        $this->sectionPreviews = $this->gatherSectionPreviews();
        $this->showGenerateModal = true;
    }

    public function toggleSection(string $section): void
    {
        if (in_array($section, $this->excludedSections)) {
            $this->excludedSections = array_values(array_diff($this->excludedSections, [$section]));
        } else {
            $this->excludedSections[] = $section;
        }
    }

    public function proceedToRecommendations(): void
    {
        $this->generateStep = 2;
        $this->loadingRecommendations = true;

        $data = $this->gatherSiteDataForRecs();
        $language = $this->site->reportConfig->language ?? 'ro';
        $recService = new ReportRecommendationService($data, $language);
        $recService->generateAndPersist($this->site);

        $this->loadDraftRecommendations();
        $this->loadingRecommendations = false;
    }

    public function backToPreview(): void
    {
        $this->generateStep = 1;
    }

    public function confirmGenerate(): void
    {
        $template = ReportTemplate::findOrFail($this->selectedTemplateId);

        $periodEnd = Carbon::today();
        $periodStart = $periodEnd->copy()->subDays(30);

        $this->dispatchTrackedJob('generate', new GenerateReport(
            site: $this->site,
            template: $template,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            trigger: 'manual',
            excludedSections: $this->excludedSections,
        ), 'Generating report...');

        $this->showGenerateModal = false;
    }

    public function toggleRecommendation(int $id): void
    {
        $rec = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->findOrFail($id);

        $rec->update(['is_included' => ! $rec->is_included]);
        $this->loadDraftRecommendations();
    }

    public function removeRecommendation(int $id): void
    {
        ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->where('id', $id)
            ->delete();

        $this->loadDraftRecommendations();
    }

    public function addCustomRecommendation(): void
    {
        $this->validate([
            'newRecTitle' => 'required|string|max:255',
            'newRecDescription' => 'required|string|max:1000',
            'newRecPriority' => 'required|in:high,medium,low',
            'newRecCategory' => 'required|in:technical,performance,seo',
        ]);

        $maxSort = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->max('sort_order') ?? -1;

        ReportRecommendation::create([
            'site_id' => $this->site->id,
            'category' => $this->newRecCategory,
            'priority' => $this->newRecPriority,
            'title' => $this->newRecTitle,
            'description' => $this->newRecDescription,
            'is_auto_generated' => false,
            'is_included' => true,
            'sort_order' => $maxSort + 1,
        ]);

        $this->newRecTitle = '';
        $this->newRecDescription = '';
        $this->newRecPriority = 'medium';
        $this->newRecCategory = 'technical';

        $this->loadDraftRecommendations();
    }

    protected function loadDraftRecommendations(): void
    {
        $this->draftRecommendations = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }

    protected function gatherSiteDataForRecs(): array
    {
        $data = [];

        // Uptime
        $monitor = $this->site->uptimeMonitor;
        if ($monitor) {
            $data['uptime'] = [
                'uptime_percentage' => $monitor->uptime_30d,
                'avg_response_time' => $monitor->avg_response_time,
                'incidents_count' => $monitor->incidents_count_30d ?? 0,
            ];
        }

        // Backups
        $config = $this->site->backupConfig;
        $data['backups'] = [
            'schedule_enabled' => (bool) ($config?->is_enabled),
            'failed_count' => 0,
        ];

        // Security
        $secMon = $this->site->securityMonitor;
        if ($secMon) {
            $data['security'] = [
                'score' => $secMon->latest_score,
                'critical_count' => 0,
            ];
        }

        // Performance
        $perfMon = $this->site->performanceMonitor;
        if ($perfMon) {
            $mobileTest = $perfMon->latestMobileTest;
            $desktopTest = $perfMon->latestDesktopTest;
            $data['performance'] = [
                'mobile_score' => $mobileTest?->performance_score,
                'desktop_score' => $desktopTest?->performance_score,
                'mobile' => $mobileTest ? [
                    'lcp_color' => $mobileTest->metricColor('lcp'),
                    'cls_color' => $mobileTest->metricColor('cls'),
                    'tbt_color' => $mobileTest->metricColor('tbt'),
                ] : [],
            ];
        }

        // Analytics
        $analyticsCache = $this->site->analyticsCaches()
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if ($analyticsCache) {
            $overview = $analyticsCache->data['overview'] ?? [];
            $data['analytics'] = [
                'bounce_rate' => $overview['bounce_rate'] ?? null,
                'avg_session_duration' => $overview['avg_session_duration'] ?? null,
            ];
        }

        // Search Console
        $scCache = $this->site->searchConsoleCaches()
            ->where('data_type', 'overview')
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if ($scCache) {
            $scData = $scCache->data ?? [];
            $data['search_console'] = [
                'overview' => [
                    'avg_position' => $scData['position'] ?? null,
                    'avg_ctr' => ($scData['ctr'] ?? 0) / 100,
                ],
            ];
        }

        return $data;
    }

    protected function gatherSectionPreviews(): array
    {
        $template = ReportTemplate::find($this->selectedTemplateId);
        $sections = $template->sections ?? [];
        $periodEnd = Carbon::today();
        $periodStart = $periodEnd->copy()->subDays(30);
        $previews = [];

        foreach ($sections as $section) {
            $preview = match ($section) {
                'overview' => $this->previewOverview(),
                'updates' => $this->previewUpdates($periodStart, $periodEnd),
                'uptime' => $this->previewUptime(),
                'backups' => $this->previewBackups($periodStart, $periodEnd),
                'analytics' => $this->previewAnalytics(),
                'search_console' => $this->previewSearchConsole(),
                'performance' => $this->previewPerformance(),
                'plugin_inventory' => $this->previewPluginInventory(),
                'database_health' => $this->previewDatabaseHealth(),
                'cloudflare' => $this->previewCloudflare(),
                'wp_users' => $this->previewWpUsers(),
                'security_checks' => $this->previewSecurityChecks(),
                default => null,
            };

            if ($preview) {
                $previews[$section] = $preview;
            }
        }

        // Always show infrastructure and recommendations (not in template sections array but always rendered)
        $previews['infrastructure'] = $this->previewInfrastructure();
        $previews['recommendations'] = $this->previewRecommendations();

        return $previews;
    }

    protected function previewOverview(): array
    {
        return [
            'label' => __('report.section_label_overview'),
            'status' => 'neutral',
            'metrics' => [],
            'sub_items' => [
                ['key' => 'overview:uptime', 'label' => __('report.snapshot_uptime')],
                ['key' => 'overview:downtime', 'label' => __('report.snapshot_downtime')],
                ['key' => 'overview:updates', 'label' => __('report.snapshot_updates')],
                ['key' => 'overview:backups', 'label' => __('report.snapshot_backups')],
                ['key' => 'overview:desktop_perf', 'label' => __('report.snapshot_desktop_perf')],
                ['key' => 'overview:mobile_perf', 'label' => __('report.snapshot_mobile_perf')],
                ['key' => 'overview:users', 'label' => __('report.snapshot_users')],
                ['key' => 'overview:impressions', 'label' => __('report.snapshot_impressions')],
            ],
        ];
    }

    protected function previewUpdates(Carbon $periodStart, Carbon $periodEnd): array
    {
        $count = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$periodStart, $periodEnd])
            ->count();

        return [
            'label' => __('report.section_label_updates'),
            'status' => 'neutral',
            'metrics' => [
                ['label' => 'Updates applied', 'value' => (string) $count],
            ],
        ];
    }

    protected function previewUptime(): array
    {
        $monitor = $this->site->uptimeMonitor;
        if (! $monitor) {
            return [
                'label' => __('report.section_label_uptime'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        $pct = $monitor->uptime_30d;
        $status = $pct === null ? 'no-data' : ($pct >= 99.5 ? 'good' : ($pct >= 95 ? 'warning' : 'bad'));

        return [
            'label' => __('report.section_label_uptime'),
            'status' => $status,
            'metrics' => [
                ['label' => 'Uptime', 'value' => $pct !== null ? number_format((float) $pct, 2).'%' : 'N/A'],
            ],
        ];
    }

    protected function previewBackups(Carbon $periodStart, Carbon $periodEnd): array
    {
        $successful = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();
        $failed = Backup::where('site_id', $this->site->id)
            ->where('status', 'failed')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();

        $status = $failed > 0 ? 'bad' : 'good';

        return [
            'label' => __('report.section_label_backups'),
            'status' => $status,
            'metrics' => [
                ['label' => 'Successful', 'value' => (string) $successful],
                ['label' => 'Failed', 'value' => (string) $failed],
            ],
        ];
    }

    protected function previewAnalytics(): array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if (! $cache) {
            return [
                'label' => __('report.section_label_analytics'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        $overview = $cache->data['overview'] ?? [];

        return [
            'label' => __('report.section_label_analytics'),
            'status' => 'neutral',
            'metrics' => [
                ['label' => 'Users', 'value' => number_format($overview['total_users'] ?? 0)],
                ['label' => 'Pageviews', 'value' => number_format($overview['pageviews'] ?? 0)],
            ],
        ];
    }

    protected function previewSearchConsole(): array
    {
        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('data_type', 'overview')
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if (! $cache) {
            return [
                'label' => __('report.section_label_search_console'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        $data = $cache->data ?? [];

        return [
            'label' => __('report.section_label_search_console'),
            'status' => 'neutral',
            'metrics' => [
                ['label' => 'Clicks', 'value' => number_format($data['clicks'] ?? 0)],
                ['label' => 'Impressions', 'value' => number_format($data['impressions'] ?? 0)],
            ],
        ];
    }

    protected function previewPerformance(): array
    {
        $monitor = $this->site->performanceMonitor;
        if (! $monitor) {
            return [
                'label' => __('report.section_label_performance'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        $mobile = $monitor->latestMobileTest?->performance_score;
        $desktop = $monitor->latestDesktopTest?->performance_score;

        $worstScore = min(array_filter([$mobile, $desktop], fn ($v) => $v !== null)) ?: null;
        $status = $worstScore === null ? 'no-data' : ($worstScore >= 90 ? 'good' : ($worstScore >= 50 ? 'warning' : 'bad'));

        return [
            'label' => __('report.section_label_performance'),
            'status' => $status,
            'metrics' => [
                ['label' => 'Desktop', 'value' => $desktop !== null ? (string) $desktop : 'N/A'],
                ['label' => 'Mobile', 'value' => $mobile !== null ? (string) $mobile : 'N/A'],
            ],
        ];
    }

    protected function previewInfrastructure(): array
    {
        $metrics = [];

        $email = $this->site->latestEmailHealthCheck;
        if ($email) {
            $parts = [];
            if ($email->spf_exists) {
                $parts[] = 'SPF';
            }
            if ($email->dkim_exists) {
                $parts[] = 'DKIM';
            }
            if ($email->dmarc_exists) {
                $parts[] = 'DMARC';
            }
            $metrics[] = ['label' => 'Email', 'value' => $parts ? implode('+', $parts) : 'None'];
        }

        if (empty($metrics)) {
            return [
                'label' => __('report.section_label_infrastructure'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        return [
            'label' => __('report.section_label_infrastructure'),
            'status' => 'neutral',
            'metrics' => $metrics,
        ];
    }

    protected function previewRecommendations(): array
    {
        $count = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->where('is_included', true)
            ->count();

        $total = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->count();

        return [
            'label' => __('report.section_label_recommendations'),
            'status' => 'neutral',
            'metrics' => [
                ['label' => 'Included', 'value' => $total > 0 ? $count.'/'.$total : 'Auto-generated'],
            ],
        ];
    }

    protected function previewPluginInventory(): array
    {
        $total = SitePlugin::where('site_id', $this->site->id)->count();
        $withUpdates = SitePlugin::where('site_id', $this->site->id)->where('has_update', true)->count();

        $status = $withUpdates > 0 ? 'warning' : 'good';

        return [
            'label' => __('report.section_label_plugin_inventory'),
            'status' => $status,
            'metrics' => [
                ['label' => 'Plugins', 'value' => (string) $total],
                ['label' => 'Need updates', 'value' => (string) $withUpdates],
            ],
        ];
    }

    protected function previewDatabaseHealth(): array
    {
        $check = DatabaseHealthCheck::where('site_id', $this->site->id)
            ->latest('checked_at')
            ->first();

        if (! $check) {
            return [
                'label' => __('report.section_label_database_health'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        return [
            'label' => __('report.section_label_database_health'),
            'status' => $check->status === 'healthy' ? 'good' : ($check->status === 'warning' ? 'warning' : 'bad'),
            'metrics' => [
                ['label' => 'Size', 'value' => $check->formatted_total_size],
                ['label' => 'Tables', 'value' => (string) $check->total_tables],
            ],
        ];
    }

    protected function previewCloudflare(): array
    {
        $cf = SiteCloudflare::where('site_id', $this->site->id)->first();

        if (! $cf || ! $cf->is_active) {
            return [
                'label' => __('report.section_label_cloudflare'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'Not active']],
            ];
        }

        $snapshot = \App\Models\SiteMonthlySnapshot::where('site_id', $this->site->id)
            ->where('year', now()->year)
            ->where('month', now()->month)
            ->first();

        $requests = $snapshot?->cloudflare_requests;
        $cacheRatio = $snapshot?->cloudflare_cache_hit_ratio;

        return [
            'label' => __('report.section_label_cloudflare'),
            'status' => 'neutral',
            'metrics' => [
                ['label' => 'Requests', 'value' => $requests !== null ? number_format($requests) : 'N/A'],
                ['label' => 'Cache hit', 'value' => $cacheRatio !== null ? number_format($cacheRatio, 1).'%' : 'N/A'],
            ],
        ];
    }

    protected function previewWpUsers(): array
    {
        $total = SiteUser::where('site_id', $this->site->id)->count();
        $admins = SiteUser::where('site_id', $this->site->id)->where('role', 'administrator')->count();

        if ($total === 0) {
            return [
                'label' => __('report.section_label_wp_users'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        return [
            'label' => __('report.section_label_wp_users'),
            'status' => 'neutral',
            'metrics' => [
                ['label' => 'Users', 'value' => (string) $total],
                ['label' => 'Admins', 'value' => (string) $admins],
            ],
        ];
    }

    protected function previewSecurityChecks(): array
    {
        $checks = SecurityRecommendation::where('site_id', $this->site->id)->get();

        if ($checks->isEmpty()) {
            return [
                'label' => __('report.section_label_security_checks'),
                'status' => 'no-data',
                'metrics' => [['label' => 'Status', 'value' => 'No data']],
            ];
        }

        $total = $checks->count();
        $passed = $checks->where('status', 'passed')->count();
        $score = $total > 0 ? round(($passed / $total) * 100) : 0;
        $status = $score >= 80 ? 'good' : ($score >= 50 ? 'warning' : 'bad');

        return [
            'label' => __('report.section_label_security_checks'),
            'status' => $status,
            'metrics' => [
                ['label' => 'Score', 'value' => $score.'%'],
                ['label' => 'Passed', 'value' => $passed.'/'.$total],
            ],
        ];
    }

    // ─── Schedule Modal ─────────────────────────────────────────────

    public function openScheduleModal(): void
    {
        /** @var ReportSchedule|null $schedule */
        $schedule = $this->site->reportSchedules()->first();

        if ($schedule) {
            $this->editingScheduleId = $schedule->id;
            $this->scheduleActive = $schedule->is_active;
            $this->scheduleTemplateId = $schedule->report_template_id;
            $this->scheduleFrequency = $schedule->frequency;
            $this->scheduleDayOfWeek = $schedule->day_of_week ?? 1;
            $this->scheduleDayOfMonth = $schedule->day_of_month ?? 1;
            $this->scheduleTime = $schedule->time ?? '08:00';
            $this->scheduleTimezone = $schedule->timezone ?? 'Europe/Bucharest';
            $this->schedulePeriod = $schedule->period ?? 'last_30_days';
            $this->scheduleRecipientEmails = implode(', ', $schedule->recipient_emails ?? []);
            $this->scheduleSendCopyToAdmin = $schedule->send_copy_to_admin;
            $this->scheduleEmailSubject = $schedule->email_subject ?? '';
            $this->scheduleEmailBody = $schedule->email_body ?? '';
            $this->scheduleClientName = $schedule->client_name ?? '';
        } else {
            $this->editingScheduleId = null;
        }

        $this->showScheduleModal = true;
    }

    public function saveSchedule(): void
    {
        $this->validate([
            'scheduleTemplateId' => 'required|exists:report_templates,id',
            'scheduleFrequency' => 'required|in:weekly,monthly',
            'scheduleTime' => 'required|date_format:H:i',
            'schedulePeriod' => 'required|in:last_7_days,last_30_days,last_month',
        ]);

        app(ReportManagementService::class)->saveSchedule($this->site, [
            'template_id' => $this->scheduleTemplateId,
            'is_active' => $this->scheduleActive,
            'frequency' => $this->scheduleFrequency,
            'day_of_week' => $this->scheduleDayOfWeek,
            'day_of_month' => $this->scheduleDayOfMonth,
            'time' => $this->scheduleTime,
            'timezone' => $this->scheduleTimezone,
            'period' => $this->schedulePeriod,
            'recipient_emails_raw' => $this->scheduleRecipientEmails,
            'send_copy_to_admin' => $this->scheduleSendCopyToAdmin,
            'email_subject' => $this->scheduleEmailSubject,
            'email_body' => $this->scheduleEmailBody,
            'client_name' => $this->scheduleClientName,
        ], $this->editingScheduleId);

        $this->showScheduleModal = false;
        session()->flash('report-success', 'Report schedule saved.');
    }

    public function deleteSchedule(): void
    {
        if ($this->editingScheduleId) {
            ReportSchedule::destroy($this->editingScheduleId);
            $this->editingScheduleId = null;
            $this->showScheduleModal = false;
            session()->flash('report-success', 'Report schedule deleted.');
        }
    }

    // ─── Send Report ────────────────────────────────────────────────

    public function openSendModal(int $reportId): void
    {
        $this->sendReportId = $reportId;
        $this->sendToEmail = '';
        $this->showSendModal = true;
    }

    public function sendReport(): void
    {
        $this->validate([
            'sendToEmail' => 'required|email',
        ]);

        /** @var \App\Models\Report $report */
        $report = $this->site->reports()->findOrFail($this->sendReportId);

        app(ReportManagementService::class)->sendReport($report, [$this->sendToEmail]);

        $this->showSendModal = false;
        $this->sendReportId = null;
        session()->flash('report-success', 'Report sent to '.$this->sendToEmail);
    }

    // ─── Bulk Actions ─────────────────────────────────────────────

    public function bulkDelete(array $ids): void
    {
        $count = app(ReportManagementService::class)->deleteReports($ids, $this->site);
        session()->flash('report-success', $count.' report(s) deleted.');
    }

    public function bulkSend(array $ids, string $email): void
    {
        $this->bulkSendEmail = $email;

        $this->validate([
            'bulkSendEmail' => 'required|email',
        ]);

        $count = app(ReportManagementService::class)->bulkSend($ids, $email, $this->site);

        $this->bulkSendEmail = '';
        session()->flash('report-success', $count.' report(s) sent to '.$email);
    }

    public function deleteReport(int $reportId): void
    {
        app(ReportManagementService::class)->deleteReports([$reportId], $this->site);
        session()->flash('report-success', 'Report deleted.');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        // Report list will auto-refresh on next render since it's a query in render()
    }

    public function render()
    {
        $schedule = $this->site->reportSchedules()->with('reportTemplate')->first();
        $reports = $this->site->reports()
            ->with('reportTemplate')
            ->orderByDesc('created_at')
            ->paginate(15);
        $templates = ReportTemplate::orderBy('name')->get();

        return view('livewire.sites.detail.site-reports', [
            'schedule' => $schedule,
            'reports' => $reports,
            'templates' => $templates,
        ])
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Reports',
            ]);
    }
}
