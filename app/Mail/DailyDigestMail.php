<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $digest,
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'SimpleAd Manager');

        return new Envelope(
            subject: "{$appName} — Daily Digest",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.daily-digest',
            with: ['digest' => $this->digest],
        );
    }
}
