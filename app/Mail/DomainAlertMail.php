<?php

namespace App\Mail;

use App\Models\DomainMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DomainAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public DomainMonitor $domainMonitor,
        public string $alertType
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->domainMonitor->site;

        $subject = match ($this->alertType) {
            'expired' => "DOMAIN EXPIRED: {$site->name}",
            'expiring_soon' => "DOMAIN EXPIRING SOON: {$site->name}",
            default => "DOMAIN ERROR: {$site->name}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.domain-alert',
            with: [
                'domainMonitor' => $this->domainMonitor,
                'site' => $this->domainMonitor->site,
                'alertType' => $this->alertType,
            ],
        );
    }
}
