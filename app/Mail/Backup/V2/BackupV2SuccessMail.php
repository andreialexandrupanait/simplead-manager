<?php

declare(strict_types=1);

namespace App\Mail\Backup\V2;

use App\Backup\V2\Models\BackupSession;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * V2 backup-success alert. New class, modelled on App\Mail\BackupAlertMail; the V1
 * mailable is untouched.
 */
class BackupV2SuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public BackupSession $session,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Backup completed: :site', ['site' => $this->site->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.backup.v2.success',
            with: [
                'site' => $this->site,
                'session' => $this->session,
            ],
        );
    }
}
