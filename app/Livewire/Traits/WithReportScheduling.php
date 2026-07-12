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

    /**
     * editingScheduleId is a client-controllable public property; a Viewer or a
     * user on another site could point it at any schedule. Block Viewers and
     * verify the edited schedule actually belongs to the current site.
     */
    private function assertScheduleManageable(): void
    {
        $user = auth()->user();
        if (! $user || $user->isViewer()) {
            abort(403, 'Viewers cannot manage report schedules.');
        }

        if ($this->editingScheduleId !== null
            && ! ReportSchedule::where('site_id', $this->site->id)
                ->whereKey($this->editingScheduleId)
                ->exists()
        ) {
            abort(403, 'That report schedule does not belong to this site.');
        }
    }

    public function saveSchedule(): void
    {
        $this->assertScheduleManageable();

        $this->validate([
            'scheduleTemplateId' => 'required|exists:report_templates,id',
            'scheduleFrequency' => 'required|in:weekly,monthly',
            'scheduleTime' => 'required|date_format:H:i',
            'schedulePeriod' => 'required|in:last_7_days,last_30_days,last_month',
            // Recipients are entered as a comma-separated string; reject the save
            // if any address is malformed so a bad entry never reaches the mailer.
            'scheduleRecipientEmails' => ['nullable', 'string', function (string $attribute, mixed $value, callable $fail): void {
                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $email) {
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail("The recipient list contains an invalid email address: {$email}");
                    }
                }
            }],
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
        $this->assertScheduleManageable();

        if ($this->editingScheduleId) {
            // Scoped delete: only ever remove a schedule owned by this site.
            ReportSchedule::where('site_id', $this->site->id)
                ->whereKey($this->editingScheduleId)
                ->delete();
            $this->editingScheduleId = null;
            $this->showScheduleModal = false;
            session()->flash('report-success', 'Report schedule deleted.');
        }
    }
}
