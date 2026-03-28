<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\ReportGeneratedMail;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ReportManagementService
{
    /**
     * Save or update a report schedule.
     */
    public function saveSchedule(Site $site, array $data, ?int $scheduleId = null): ReportSchedule
    {
        $recipients = [];
        if (! empty($data['recipient_emails_raw'])) {
            $recipients = array_filter(array_map('trim', explode(',', $data['recipient_emails_raw'])));
        }

        $scheduleData = [
            'site_id' => $site->id,
            'report_template_id' => $data['template_id'],
            'is_active' => $data['is_active'] ?? true,
            'frequency' => $data['frequency'],
            'day_of_week' => $data['frequency'] === 'weekly' ? ($data['day_of_week'] ?? 1) : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? ($data['day_of_month'] ?? 1) : null,
            'time' => $data['time'] ?? '08:00',
            'timezone' => $data['timezone'] ?? 'Europe/Bucharest',
            'period' => $data['period'] ?? 'last_30_days',
            'recipient_emails' => $recipients,
            'send_copy_to_admin' => $data['send_copy_to_admin'] ?? false,
            'email_subject' => $data['email_subject'] ?: null,
            'email_body' => $data['email_body'] ?: null,
            'client_name' => $data['client_name'] ?: null,
        ];

        if ($scheduleId) {
            $schedule = ReportSchedule::findOrFail($scheduleId);
            $schedule->update($scheduleData);
        } else {
            $schedule = ReportSchedule::create($scheduleData);
        }

        $schedule->update(['next_run_at' => $schedule->calculateNextRun()]);

        return $schedule;
    }

    /**
     * Send a report to specified recipients.
     */
    public function sendReport(Report $report, array $emails): void
    {
        $site = $report->site;
        $schedule = $report->reportSchedule;

        foreach ($emails as $email) {
            Mail::to(trim($email))->send(new ReportGeneratedMail($report, $site, $schedule));
        }

        $report->update([
            'was_sent' => true,
            'sent_at' => now(),
            'sent_to' => array_merge($report->sent_to ?? [], $emails),
        ]);

        ActivityLogger::reportSent($site, $report->title, $emails);
    }

    /**
     * Delete reports and their files.
     */
    public function deleteReports(array $reportIds, Site $site): int
    {
        $reports = Report::where('site_id', $site->id)
            ->whereIn('id', $reportIds)
            ->get();

        foreach ($reports as $report) {
            if ($report->file_path) {
                Storage::disk('local')->delete($report->file_path);
            }
            $report->delete();
        }

        return $reports->count();
    }

    /**
     * Bulk send reports to an email address.
     */
    public function bulkSend(array $reportIds, string $email, Site $site): int
    {
        $reports = Report::where('site_id', $site->id)
            ->whereIn('id', $reportIds)
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->get();

        foreach ($reports as $report) {
            try {
                Mail::to($email)->send(new ReportGeneratedMail($report, $site, null));
                $report->update([
                    'was_sent' => true,
                    'sent_at' => now(),
                    'sent_to' => array_merge($report->sent_to ?? [], [$email]),
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to send report {$report->id} to {$email}: {$e->getMessage()}");
            }
        }

        return $reports->count();
    }
}
