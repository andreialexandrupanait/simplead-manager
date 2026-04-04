<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\ReportSchedule;
use App\Services\ReportManagementService;

trait WithReportScheduling
{
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
}
