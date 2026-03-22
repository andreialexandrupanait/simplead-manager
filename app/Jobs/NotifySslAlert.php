<?php

namespace App\Jobs;

use App\Mail\SslAlertMail;
use App\Models\SslCertificate;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifySslAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public SslCertificate $certificate,
        public string $alertType
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $site = $this->certificate->site;

        $event = match ($this->alertType) {
            'expired' => 'ssl_expired',
            'expiring_soon' => 'ssl_expiring',
            default => 'ssl_error',
        };

        $severity = match ($this->alertType) {
            'expired' => 'critical',
            'expiring_soon' => 'warning',
            default => 'critical',
        };

        $title = match ($this->alertType) {
            'expired' => "SSL EXPIRED: {$site->name}",
            'expiring_soon' => "SSL EXPIRING SOON: {$site->name}",
            default => "SSL ERROR: {$site->name}",
        };

        $message = match ($this->alertType) {
            'expired' => "The SSL certificate for {$this->certificate->domain} has expired.",
            'expiring_soon' => "The SSL certificate for {$this->certificate->domain} expires in {$this->certificate->days_remaining} days.",
            default => "There is an SSL error for {$this->certificate->domain}.",
        };

        $fields = [
            ['title' => 'Domain', 'value' => $this->certificate->domain, 'short' => true],
            ['title' => 'Days Remaining', 'value' => (string) ($this->certificate->days_remaining ?? 'N/A'), 'short' => true],
            ['title' => 'Issuer', 'value' => $this->certificate->issuer ?? 'Unknown', 'short' => true],
            ['title' => 'Expires', 'value' => $this->certificate->expires_at?->format('M d, Y') ?? 'Unknown', 'short' => true],
        ];

        $webhookPayload = [
            'alert_type' => $this->alertType,
            'certificate' => [
                'domain' => $this->certificate->domain,
                'issuer' => $this->certificate->issuer,
                'expires_at' => $this->certificate->expires_at?->toIso8601String(),
                'days_remaining' => $this->certificate->days_remaining,
                'status' => $this->certificate->status,
            ],
        ];

        NotificationService::notifySiteEvent(
            site: $site,
            event: $event,
            title: $title,
            message: $message,
            fields: $fields,
            severity: $severity,
            webhookPayload: $webhookPayload,
            mailableClass: SslAlertMail::class,
            mailableArgs: [$this->certificate, $this->alertType],
        );

        $this->certificate->update(['last_alert_sent_at' => now()]);
    }
}
