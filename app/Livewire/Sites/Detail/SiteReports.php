<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\GenerateReport;
use App\Mail\ReportGeneratedMail;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Livewire\WithPagination;

class SiteReports extends Component
{
    use WithPagination;

    public Site $site;

    // Generate modal
    public bool $showGenerateModal = false;
    public ?int $selectedTemplateId = null;
    public string $period = 'last_30_days';
    public ?string $customStart = null;
    public ?string $customEnd = null;
    public string $recipientEmails = '';

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

    public function mount(Site $site): void
    {
        $this->site = $site;

        $defaultTemplate = ReportTemplate::where('is_default', true)->first()
            ?? ReportTemplate::first();
        $this->selectedTemplateId = $defaultTemplate?->id;
        $this->scheduleTemplateId = $defaultTemplate?->id;
    }

    public function openGenerateModal(): void
    {
        $this->showGenerateModal = true;
    }

    public function generateReport(): void
    {
        $this->validate([
            'selectedTemplateId' => 'required|exists:report_templates,id',
            'period' => 'required|in:last_7_days,last_30_days,last_month,custom',
            'customStart' => 'required_if:period,custom|nullable|date',
            'customEnd' => 'required_if:period,custom|nullable|date|after_or_equal:customStart',
        ]);

        $template = ReportTemplate::findOrFail($this->selectedTemplateId);
        [$periodStart, $periodEnd] = $this->calculatePeriod($this->period, $this->customStart, $this->customEnd);

        $recipients = $this->recipientEmails
            ? array_map('trim', explode(',', $this->recipientEmails))
            : [];
        $recipients = array_filter($recipients);

        GenerateReport::dispatch(
            $this->site,
            $template,
            $periodStart,
            $periodEnd,
            'manual',
            null,
            count($recipients) > 0 ? $recipients : null,
        );

        $this->showGenerateModal = false;
        $this->recipientEmails = '';
        session()->flash('report-success', 'Report generation started. It will appear in the history once completed.');
    }

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
        $schedule->update(['next_run_at' => $this->calculateNextRun($schedule)]);

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

    protected function calculatePeriod(string $period, ?string $customStart, ?string $customEnd): array
    {
        return match ($period) {
            'last_7_days' => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
            'last_30_days' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'custom' => [Carbon::parse($customStart)->startOfDay(), Carbon::parse($customEnd)->endOfDay()],
            default => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
        };
    }

    protected function calculateNextRun(ReportSchedule $schedule): Carbon
    {
        $tz = $schedule->timezone ?? 'Europe/Bucharest';
        [$hour, $minute] = explode(':', $schedule->time ?? '08:00');

        if ($schedule->frequency === 'weekly') {
            $next = now($tz)->next(Carbon::getDays()[$schedule->day_of_week ?? 0]);
            $next->setTime((int) $hour, (int) $minute);
        } else {
            $dayOfMonth = $schedule->day_of_month ?? 1;
            $next = now($tz)->addMonth()->setDay(min($dayOfMonth, now($tz)->addMonth()->daysInMonth));
            $next->setTime((int) $hour, (int) $minute);
        }

        return $next->utc();
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
