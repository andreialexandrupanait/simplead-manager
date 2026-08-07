<?php

declare(strict_types=1);

namespace App\Mail\Backup\V2;

use App\Models\StorageDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * V2 storage-limit-reached alert (P5). Fired on reconciled `used_bytes` crossing
 * the quota / warning threshold. New class; V1 storage/budget mail is untouched.
 */
class StorageQuotaReachedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StorageDestination $destination,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Storage limit reached: :destination', ['destination' => $this->destination->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.backup.v2.quota-reached',
            with: [
                'destination' => $this->destination,
            ],
        );
    }
}
