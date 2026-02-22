<?php

namespace App\Mail;

use App\Models\SslCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SslAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SslCertificate $certificate,
        public string $alertType
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->certificate->site;

        $subject = match ($this->alertType) {
            'expired' => "SSL EXPIRED: {$site->name}",
            'expiring_soon' => "SSL EXPIRING SOON: {$site->name}",
            default => "SSL ERROR: {$site->name}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.ssl-alert',
            with: [
                'certificate' => $this->certificate,
                'site' => $this->certificate->site,
                'alertType' => $this->alertType,
            ],
        );
    }
}
