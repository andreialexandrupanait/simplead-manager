<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReportStatus;
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
use Illuminate\Support\Str;

class GenerateReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Sized to the worst-case Gotenberg render budget (~4 × per-call timeout) plus
    // margin; overridden from config in the constructor. The previous 300s ceiling
    // was shorter than that budget, so a slow render got SIGKILLed mid-flight.
    public int $timeout = 540;

    public int $memory = 512;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public array $backoff = [60, 120];

    public ?int $reportId = null;

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
        $this->timeout = (int) config('services.gotenberg.job_timeout', 540);
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

        // Idempotency: reuse existing report on retry
        $report = $this->reportId ? Report::find($this->reportId) : null;

        // Dedup check: look for a recent report for this schedule+period
        if (! $report && $this->schedule) {
            $report = Report::where('report_schedule_id', $this->schedule->id)
                ->where('period_start', $this->periodStart->toDateString())
                ->where('period_end', $this->periodEnd->toDateString())
                ->whereIn('status', ['generating', 'completed'])
                ->where('created_at', '>=', now()->subHours(1))
                ->first();
        }

        if ($report && $report->status === ReportStatus::Completed && $report->was_sent) {
            Log::info("Report #{$report->id} already completed and sent, skipping", [
                'site_id' => $this->site->id,
                'schedule_id' => $this->schedule?->id,
            ]);
            JobTracker::complete($this->trackerKey(), 'Report already generated');

            return;
        }

        if (! $report) {
            $report = Report::create([
                'site_id' => $this->site->id,
                'report_template_id' => $this->template->id,
                'report_schedule_id' => $this->schedule?->id,
                'title' => 'Maintenance Report - '.$this->site->name.' - '.$this->periodEnd->format('d.m.Y'),
                'period_start' => $this->periodStart,
                'period_end' => $this->periodEnd,
                'status' => 'generating',
                'trigger' => $this->trigger,
                'view_token' => Str::random(32),
            ]);
        } else {
            $report->update(['status' => 'generating', 'error_message' => null]);
        }

        $this->reportId = $report->id;

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
            $fileSize = file_exists($fullPath) ? (int) filesize($fullPath) : 0;
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

            // Update schedule success timestamps (before email — PDF is the critical deliverable)
            if ($this->schedule) {
                $this->schedule->update([
                    'last_generated_at' => now(),
                    'consecutive_failures' => 0,
                    'last_failure_reason' => null,
                ]);
            }

            // Verify PDF integrity before sending emails
            $pdfVerified = $this->verifyPdf($fullPath);

            JobTracker::complete($this->trackerKey(), 'Report generated successfully');

            // Guard: skip email if already sent (idempotency on retry)
            if ($report->was_sent) {
                Log::info("Report #{$report->id} emails already sent, skipping", [
                    'site_id' => $this->site->id,
                ]);
            } else {
                // Send emails (best-effort — don't fail the report if email fails).
                // Each recipient (client + admin if send_copy_to_admin) gets a separate
                // email — like Postmark events: one email per (client, report).
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
                    // Iterate defensively: one malformed address (or a single failed
                    // send) must not abort delivery to the rest of the list. Skip
                    // invalid recipients, log per-recipient failures, and only mark
                    // the report sent for the addresses that actually succeeded.
                    $sent = [];
                    foreach ($recipients as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            Log::warning("Skipping invalid report recipient for site {$this->site->id}, report #{$report->id}", [
                                'email' => $email,
                            ]);

                            continue;
                        }

                        try {
                            Mail::to($email)->send(new ReportGeneratedMail($report, $this->site, $this->schedule, $pdfVerified));
                            $sent[] = $email;
                        } catch (\Throwable $e) {
                            Log::warning("Report email failed for site {$this->site->id}, report #{$report->id}", [
                                'exception' => get_class($e),
                                'message' => Str::limit($e->getMessage(), 200),
                                'email' => $email,
                            ]);
                        }
                    }

                    if (count($sent) > 0) {
                        $report->update([
                            'was_sent' => true,
                            'sent_at' => now(),
                            'sent_to' => $sent,
                        ]);

                        if ($this->schedule) {
                            $this->schedule->update(['last_sent_at' => now()]);
                        }

                        ActivityLogger::reportSent($this->site, $report->title, $sent);
                    }
                }
            }
        } catch (\Throwable $e) {
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Track consecutive failures on the schedule
            if ($this->schedule) {
                $failures = ($this->schedule->consecutive_failures ?? 0) + 1;
                $updateData = [
                    'consecutive_failures' => $failures,
                    'last_failure_reason' => Str::limit($e->getMessage(), 500),
                ];

                if ($failures >= 3) {
                    $updateData['is_active'] = false;
                    Log::warning("Report schedule #{$this->schedule->id} auto-deactivated after {$failures} consecutive failures", [
                        'site_id' => $this->site->id,
                        'last_error' => $e->getMessage(),
                    ]);
                }

                $this->schedule->update($updateData);
            }

            JobTracker::fail($this->trackerKey(), 'Report generation failed');

            Log::error("Report generation failed for site {$this->site->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        } finally {
            // Safety net: if next_run_at was not advanced by the dispatcher,
            // advance it now to prevent infinite dispatch loops.
            if ($this->schedule && $this->schedule->is_active) {
                $this->schedule->refresh();
                if ($this->schedule->next_run_at === null || $this->schedule->next_run_at->lte(now())) {
                    $this->schedule->update([
                        'next_run_at' => $this->schedule->calculateNextRun(),
                        'reminder_sent_at' => null,
                    ]);
                }
            }
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

    /**
     * Verify the PDF file exists and is valid (magic bytes + minimum size).
     */
    protected function verifyPdf(string $fullPath): bool
    {
        if (! file_exists($fullPath)) {
            Log::warning("Report PDF not found at {$fullPath}", ['site_id' => $this->site->id]);

            return false;
        }

        $size = (int) filesize($fullPath);
        if ($size < 1024) {
            Log::warning("Report PDF suspiciously small ({$size} bytes)", [
                'path' => $fullPath,
                'site_id' => $this->site->id,
            ]);

            return false;
        }

        $handle = fopen($fullPath, 'rb');
        if (! $handle) {
            return false;
        }
        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            Log::warning('Report PDF has invalid header', [
                'path' => $fullPath,
                'site_id' => $this->site->id,
            ]);

            return false;
        }

        return true;
    }
}
