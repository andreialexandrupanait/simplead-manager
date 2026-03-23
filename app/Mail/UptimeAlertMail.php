<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\UptimeIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UptimeAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public UptimeIncident $incident,
        public string $type
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->incident->monitor->site;

        return new Envelope(
            subject: $this->type === 'down'
                ? "DOWN: {$site->name}"
                : "RECOVERED: {$site->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.uptime-alert',
            with: [
                'incident' => $this->incident,
                'monitor' => $this->incident->monitor,
                'site' => $this->incident->monitor->site,
                'type' => $this->type,
            ],
        );
    }
}
