<?php

namespace App\Jobs;

use App\Mail\DomainAlertMail;
use App\Models\DomainMonitor;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyDomainAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public DomainMonitor $domainMonitor,
        public string $alertType
    ) {}

    public function handle(): void
    {
        $site = $this->domainMonitor->site;

        $event = 'domain_expiring';
        $severity = match ($this->alertType) {
            'expired' => 'critical',
            default => 'warning',
        };

        $title = match ($this->alertType) {
            'expired' => "DOMAIN EXPIRED: {$site->name}",
            'expiring_soon' => "DOMAIN EXPIRING SOON: {$site->name}",
            default => "DOMAIN ERROR: {$site->name}",
        };

        $message = match ($this->alertType) {
            'expired' => "The domain {$this->domainMonitor->domain} has expired.",
            'expiring_soon' => "The domain {$this->domainMonitor->domain} expires in {$this->domainMonitor->days_remaining} days.",
            default => "There is a domain error for {$this->domainMonitor->domain}.",
        };

        $fields = [
            ['title' => 'Domain', 'value' => $this->domainMonitor->domain, 'short' => true],
            ['title' => 'Days Remaining', 'value' => (string) ($this->domainMonitor->days_remaining ?? 'N/A'), 'short' => true],
            ['title' => 'Registrar', 'value' => $this->domainMonitor->registrar ?? 'Unknown', 'short' => true],
            ['title' => 'Expires', 'value' => $this->domainMonitor->expires_at?->format('M d, Y') ?? 'Unknown', 'short' => true],
        ];

        $webhookPayload = [
            'alert_type' => $this->alertType,
            'domain' => [
                'name' => $this->domainMonitor->domain,
                'registrar' => $this->domainMonitor->registrar,
                'expires_at' => $this->domainMonitor->expires_at?->toIso8601String(),
                'days_remaining' => $this->domainMonitor->days_remaining,
                'status' => $this->domainMonitor->status,
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
            mailableClass: DomainAlertMail::class,
            mailableArgs: [$this->domainMonitor, $this->alertType],
        );

        $this->domainMonitor->update(['last_alert_sent_at' => now()]);
    }
}
