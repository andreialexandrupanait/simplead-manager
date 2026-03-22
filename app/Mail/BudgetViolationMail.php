<?php

namespace App\Mail;

use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BudgetViolationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PerformanceMonitor $monitor,
        public array $violations,
        public PerformanceTest $test
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->monitor->site;

        return new Envelope(
            subject: "BUDGET EXCEEDED: {$site->name} — ".count($this->violations).' violation(s)'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.budget-violation',
            with: [
                'monitor' => $this->monitor,
                'site' => $this->monitor->site,
                'violations' => $this->violations,
                'test' => $this->test,
            ],
        );
    }
}
