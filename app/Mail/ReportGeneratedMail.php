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

    /**
     * Client-facing, and pinned to Romanian on purpose.
     *
     * This template was hardcoded Romanian while every other one was English,
     * because its readers are the agency's Romanian-speaking clients — 18 live
     * schedules send it. Translating it and letting it follow `app.locale`
     * (which is `en` in production) would have silently switched all of them to
     * English. When clients need different languages, the right fix is a field
     * on the client, not the app default.
     */
    private const RECIPIENT_LOCALE = 'ro';

    public function __construct(
        public Report $report,
        public Site $site,
        public ?ReportSchedule $schedule = null,
        public bool $pdfVerified = true,
    ) {
        // Set here, not in envelope(): Mailable::send() reads $this->locale as
        // the argument to withLocale() before running the closure that calls
        // envelope(), so a locale set there would arrive too late to apply.
        $this->locale(self::RECIPIENT_LOCALE);
    }

    public function envelope(): Envelope
    {
        $subject = $this->schedule?->email_subject
            ?? __('Report :site — :from - :to', [
                'site' => $this->site->name,
                'from' => $this->report->period_start->format('d.m.Y'),
                'to' => $this->report->period_end->format('d.m.Y'),
            ]);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $viewUrl = $this->report->view_token
            ? route('reports.view.public', [$this->report, $this->report->view_token])
            : null;

        $hasAttachment = $this->pdfVerified && ! empty($this->attachments());

        return new Content(
            view: 'mail.report-generated',
            with: [
                'report' => $this->report,
                'site' => $this->site,
                'schedule' => $this->schedule,
                'viewUrl' => $viewUrl,
                'pdfAttached' => $hasAttachment,
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
        if (! $this->pdfVerified || ! $this->report->file_path) {
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
