<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class ReportGeneratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Report $report,
        public Site $site,
        public ?ReportSchedule $schedule = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->schedule?->email_subject
            ?? "Raport {$this->site->name} — {$this->report->period_start->format('d.m.Y')} - {$this->report->period_end->format('d.m.Y')}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $viewUrl = $this->report->view_token
            ? route('reports.view.public', [$this->report, $this->report->view_token])
            : null;

        return new Content(
            view: 'mail.report-generated',
            with: [
                'report' => $this->report,
                'site' => $this->site,
                'schedule' => $this->schedule,
                'viewUrl' => $viewUrl,
                'downloadUrl' => URL::temporarySignedRoute(
                    'reports.download.signed',
                    now()->addDays(7),
                    ['report' => $this->report->id],
                ),
            ],
        );
    }

    public function attachments(): array
    {
        if (! $this->report->file_path) {
            return [];
        }

        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($this->report->file_path);
        if (! file_exists($fullPath)) {
            return [];
        }

        return [
            Attachment::fromPath($fullPath)
                ->as($this->report->file_name ?? 'report.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
