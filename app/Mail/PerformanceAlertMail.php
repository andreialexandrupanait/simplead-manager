<?php

namespace App\Mail;

use App\Models\PerformanceMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PerformanceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PerformanceMonitor $monitor,
        public string $device,
        public int $previousScore,
        public int $currentScore
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->monitor->site;

        return new Envelope(
            subject: "PERFORMANCE DROP: {$site->name} ({$this->device})"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.performance-alert',
            with: [
                'monitor' => $this->monitor,
                'site' => $this->monitor->site,
                'device' => $this->device,
                'previousScore' => $this->previousScore,
                'currentScore' => $this->currentScore,
                'drop' => $this->previousScore - $this->currentScore,
            ],
        );
    }
}
