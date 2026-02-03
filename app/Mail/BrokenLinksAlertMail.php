<?php

namespace App\Mail;

use App\Models\LinkMonitor;
use App\Models\LinkScan;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BrokenLinksAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LinkMonitor $monitor,
        public LinkScan $scan,
        public int $brokenCount,
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->monitor->site;

        return new Envelope(
            subject: "BROKEN LINKS: {$this->brokenCount} found on {$site->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.broken-links-alert',
            with: [
                'monitor' => $this->monitor,
                'scan' => $this->scan,
                'site' => $this->monitor->site,
                'brokenCount' => $this->brokenCount,
            ],
        );
    }
}
