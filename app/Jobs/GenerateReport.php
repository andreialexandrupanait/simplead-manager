<?php

namespace App\Jobs;

use App\Mail\ReportGeneratedMail;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\ReportGeneratorService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GenerateReport implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $memory = 512;
    public int $tries = 2;
    public array $backoff = [60, 120];

    public function __construct(
        public Site $site,
        public ReportTemplate $template,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $trigger = 'manual',
        public ?ReportSchedule $schedule = null,
        public ?array $recipientEmails = null,
    ) {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return 'report-' . $this->site->id . '-' . $this->template->id;
    }

    public function handle(): void
    {
        $report = Report::create([
            'site_id' => $this->site->id,
            'report_template_id' => $this->template->id,
            'report_schedule_id' => $this->schedule?->id,
            'title' => 'Maintenance Report — ' . $this->site->name,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'status' => 'generating',
            'trigger' => $this->trigger,
        ]);

        try {
            $service = new ReportGeneratorService(
                $this->site,
                $this->template,
                $this->periodStart,
                $this->periodEnd,
            );

            $filePath = $service->generate();
            $fullPath = Storage::disk('local')->path($filePath);
            $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
            $fileName = basename($filePath);

            $report->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'data_snapshot' => $service->getData(),
                'generated_at' => now(),
            ]);

            ActivityLogger::reportGenerated($this->site, $report->title);

            // Send emails
            $recipients = $this->recipientEmails ?? [];
            if ($this->schedule) {
                $recipients = array_merge($recipients, $this->schedule->recipient_emails ?? []);
                if ($this->schedule->send_copy_to_admin) {
                    $adminEmail = config('mail.from.address');
                    if ($adminEmail && !in_array($adminEmail, $recipients)) {
                        $recipients[] = $adminEmail;
                    }
                }
            }

            $recipients = array_unique(array_filter($recipients));

            if (count($recipients) > 0) {
                foreach ($recipients as $email) {
                    Mail::to($email)->send(new ReportGeneratedMail($report, $this->site, $this->schedule));
                }

                $report->update([
                    'was_sent' => true,
                    'sent_at' => now(),
                    'sent_to' => $recipients,
                ]);

                ActivityLogger::reportSent($this->site, $report->title, $recipients);
            }

            // Update schedule timestamps
            if ($this->schedule) {
                $updateData = ['last_generated_at' => now()];
                if (count($recipients) > 0) {
                    $updateData['last_sent_at'] = now();
                }
                $updateData['next_run_at'] = $this->schedule->calculateNextRun();
                $this->schedule->update($updateData);
            }
        } catch (\Throwable $e) {
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error("Report generation failed for site {$this->site->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }

}
