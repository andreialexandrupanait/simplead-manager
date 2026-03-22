<?php

namespace App\Jobs;

use App\Mail\ReportGeneratedMail;
use App\Models\Report;
use App\Models\ReportRecommendation;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\JobTracker;
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

class GenerateReport implements ShouldBeUnique, ShouldQueue
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
        public array $excludedSections = [],
    ) {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return 'report-'.$this->site->id.'-'.$this->template->id;
    }

    public function trackerKey(): string
    {
        return 'report-generate-'.$this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->trackerKey(), 'Generating report...');

        $report = Report::create([
            'site_id' => $this->site->id,
            'report_template_id' => $this->template->id,
            'report_schedule_id' => $this->schedule?->id,
            'title' => 'Maintenance Report - '.$this->site->name.' - '.$this->periodEnd->format('d.m.Y'),
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
                $this->excludedSections,
            );

            JobTracker::progress($this->trackerKey(), 20, 'Gathering data...');
            $filePath = $service->generate();
            JobTracker::progress($this->trackerKey(), 70, 'Saving report...');

            // Link draft recommendations to this report AFTER generation
            // so gatherData() can read is_included state from unlinked drafts
            ReportRecommendation::where('site_id', $this->site->id)
                ->whereNull('report_id')
                ->update(['report_id' => $report->id]);

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

            // Upload to Dropbox (best-effort)
            try {
                $destination = StorageDestination::where('is_default', true)
                    ->where('is_active', true)
                    ->first();

                $reportsPath = $destination?->config['reports_path'] ?? null;

                if ($destination && $reportsPath) {
                    $driver = StorageFactory::make($destination);
                    $remotePath = rtrim($reportsPath, '/').'/'.$this->site->domain.'/'.$this->periodEnd->format('Y').'/'.$fileName;
                    $driver->uploadToAbsolutePath($fullPath, $remotePath);
                }
            } catch (\Throwable $e) {
                Log::warning("Report Dropbox upload failed for site {$this->site->id}", [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);
            }

            // Send emails
            $recipients = $this->recipientEmails ?? [];
            if ($this->schedule) {
                $recipients = array_merge($recipients, $this->schedule->recipient_emails ?? []);
                if ($this->schedule->send_copy_to_admin) {
                    $adminEmail = config('mail.from.address');
                    if ($adminEmail && ! in_array($adminEmail, $recipients)) {
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
                $updateData = [
                    'last_generated_at' => now(),
                    'reminder_sent_at' => null,
                ];
                if (count($recipients) > 0) {
                    $updateData['last_sent_at'] = now();
                }
                $updateData['next_run_at'] = $this->schedule->calculateNextRun();
                $this->schedule->update($updateData);
            }

            JobTracker::complete($this->trackerKey(), 'Report generated successfully');
        } catch (\Throwable $e) {
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            JobTracker::fail($this->trackerKey(), 'Report generation failed');

            Log::error("Report generation failed for site {$this->site->id}", [
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail($this->trackerKey(), 'Report generation failed');
        Log::error("Report generation job permanently failed for site {$this->site->id}", [
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'code' => $exception?->getCode(),
        ]);
    }
}
