<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\GenerateReport;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Mail\ReportGeneratedMail;
use App\Models\ReportRecommendation;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\ReportRecommendationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Livewire\WithPagination;

class SiteReports extends Component
{
    use WithPagination, WithSiteAuthorization;

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

    // Generate modal
    public bool $showGenerateModal = false;
    public $draftRecommendations = [];
    public string $newRecTitle = '';
    public string $newRecDescription = '';
    public string $newRecPriority = 'medium';
    public string $newRecCategory = 'technical';
    public bool $loadingRecommendations = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        $defaultTemplate = ReportTemplate::where('is_default', true)->first()
            ?? ReportTemplate::first();
        $this->selectedTemplateId = $defaultTemplate?->id;
        $this->scheduleTemplateId = $defaultTemplate?->id;
    }

    // ─── Generate Report Modal Flow ─────────────────────────────────

    public function openGenerateModal(): void
    {
        $this->showGenerateModal = true;
        $this->loadingRecommendations = true;

        // Regenerate auto-suggestions based on current site data
        $data = $this->gatherSiteDataForRecs();
        $language = $this->site->reportConfig?->language ?? 'ro';
        $recService = new ReportRecommendationService($data, $language);
        $recService->generateAndPersist($this->site);

        $this->loadDraftRecommendations();
        $this->loadingRecommendations = false;
    }

    public function confirmGenerate(): void
    {
        $template = ReportTemplate::findOrFail($this->selectedTemplateId);

        $periodEnd = Carbon::today();
        $periodStart = $periodEnd->copy()->subDays(30);

        GenerateReport::dispatch(
            site: $this->site,
            template: $template,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            trigger: 'manual',
        );

        $this->showGenerateModal = false;
        session()->flash('report-success', 'Report generation started. It will appear in the list when ready.');
    }

    public function toggleRecommendation(int $id): void
    {
        $rec = ReportRecommendation::where('site_id', $this->site->id)
            ->whereNull('report_id')
            ->findOrFail($id);

        $rec->update(['is_included' => !$rec->is_included]);
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
            ->latest()
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

    // ─── Schedule Modal ─────────────────────────────────────────────

    public function openScheduleModal(): void
    {
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

        $recipients = $this->scheduleRecipientEmails
            ? array_map('trim', explode(',', $this->scheduleRecipientEmails))
            : [];
        $recipients = array_filter($recipients);

        $data = [
            'site_id' => $this->site->id,
            'report_template_id' => $this->scheduleTemplateId,
            'is_active' => $this->scheduleActive,
            'frequency' => $this->scheduleFrequency,
            'day_of_week' => $this->scheduleFrequency === 'weekly' ? $this->scheduleDayOfWeek : null,
            'day_of_month' => $this->scheduleFrequency === 'monthly' ? $this->scheduleDayOfMonth : null,
            'time' => $this->scheduleTime,
            'timezone' => $this->scheduleTimezone,
            'period' => $this->schedulePeriod,
            'recipient_emails' => $recipients,
            'send_copy_to_admin' => $this->scheduleSendCopyToAdmin,
            'email_subject' => $this->scheduleEmailSubject ?: null,
            'email_body' => $this->scheduleEmailBody ?: null,
            'client_name' => $this->scheduleClientName ?: null,
        ];

        if ($this->editingScheduleId) {
            $schedule = ReportSchedule::findOrFail($this->editingScheduleId);
            $schedule->update($data);
        } else {
            $schedule = ReportSchedule::create($data);
        }

        // Calculate next run
        $schedule->update(['next_run_at' => $schedule->calculateNextRun()]);

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

        $report = $this->site->reports()->findOrFail($this->sendReportId);

        Mail::to($this->sendToEmail)->send(new ReportGeneratedMail($report, $this->site));

        $sentTo = $report->sent_to ?? [];
        $sentTo[] = $this->sendToEmail;
        $report->update([
            'was_sent' => true,
            'sent_at' => now(),
            'sent_to' => array_unique($sentTo),
        ]);

        $this->showSendModal = false;
        $this->sendReportId = null;
        session()->flash('report-success', 'Report sent to ' . $this->sendToEmail);
    }

    public function deleteReport(int $reportId): void
    {
        $report = $this->site->reports()->findOrFail($reportId);

        if ($report->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($report->file_path);
        }

        $report->delete();
        session()->flash('report-success', 'Report deleted.');
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
                'title' => $this->site->name . ' — Reports',
            ]);
    }
}
