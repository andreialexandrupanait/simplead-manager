<?php

namespace App\Mail;

use App\Models\Backup;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackupAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public Backup $backup,
        public string $errorMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "BACKUP FAILED: {$this->site->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.backup-alert',
            with: [
                'site' => $this->site,
                'backup' => $this->backup,
                'errorMessage' => $this->errorMessage,
            ],
        );
    }
}
