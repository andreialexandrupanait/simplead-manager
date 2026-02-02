<?php

namespace App\Jobs;

use App\Mail\DomainAlertMail;
use App\Models\DomainMonitor;
use App\Models\NotificationChannel;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifyDomainAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public DomainMonitor $domainMonitor,
        public string $alertType
    ) {}

    public function handle(SettingsService $settings): void
    {
        if ($this->isQuietHours($settings)) {
            return;
        }

        $channels = $this->resolveChannels();

        foreach ($channels as $channel) {
            if (!$channel->is_active) {
                continue;
            }

            try {
                match ($channel->type) {
                    'email' => $this->sendEmail($channel),
                    'slack' => $this->sendSlack($channel),
                    'discord' => $this->sendDiscord($channel),
                    'webhook' => $this->sendWebhook($channel),
                    default => null,
                };

                $channel->update(['last_used_at' => now()]);
            } catch (\Exception $e) {
                report($e);
            }
        }

        $this->domainMonitor->update(['last_alert_sent_at' => now()]);
    }

    protected function isQuietHours(SettingsService $settings): bool
    {
        $enabled = $settings->get('quiet_hours_enabled', false);
        if (!$enabled) {
            return false;
        }

        $start = $settings->get('quiet_hours_start', '22:00');
        $end = $settings->get('quiet_hours_end', '07:00');

        $now = now()->format('H:i');

        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        return $now >= $start || $now <= $end;
    }

    protected function resolveChannels()
    {
        return NotificationChannel::where('is_default', true)->where('is_active', true)->get();
    }

    protected function sendEmail(NotificationChannel $channel): void
    {
        $address = $channel->config['address'] ?? null;
        if (!$address) {
            return;
        }

        Mail::to($address)->queue(new DomainAlertMail($this->domainMonitor, $this->alertType));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $site = $this->domainMonitor->site;
        $color = match ($this->alertType) {
            'expired' => '#DC2626',
            'expiring_soon' => '#EAB308',
            default => '#DC2626',
        };
        $title = match ($this->alertType) {
            'expired' => "DOMAIN EXPIRED: {$site->name}",
            'expiring_soon' => "DOMAIN EXPIRING SOON: {$site->name}",
            default => "DOMAIN ERROR: {$site->name}",
        };

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => $color,
                'title' => $title,
                'fields' => [
                    ['title' => 'Domain', 'value' => $this->domainMonitor->domain, 'short' => true],
                    ['title' => 'Days Remaining', 'value' => (string) ($this->domainMonitor->days_remaining ?? 'N/A'), 'short' => true],
                    ['title' => 'Registrar', 'value' => $this->domainMonitor->registrar ?? 'Unknown', 'short' => true],
                    ['title' => 'Expires', 'value' => $this->domainMonitor->expires_at?->format('M d, Y') ?? 'Unknown', 'short' => true],
                ],
                'ts' => now()->timestamp,
            ]],
        ]);
    }

    protected function sendDiscord(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $site = $this->domainMonitor->site;
        $color = match ($this->alertType) {
            'expired' => 0xDC2626,
            'expiring_soon' => 0xEAB308,
            default => 0xDC2626,
        };

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => match ($this->alertType) {
                    'expired' => "DOMAIN EXPIRED: {$site->name}",
                    'expiring_soon' => "DOMAIN EXPIRING SOON: {$site->name}",
                    default => "DOMAIN ERROR: {$site->name}",
                },
                'color' => $color,
                'fields' => [
                    ['name' => 'Domain', 'value' => $this->domainMonitor->domain, 'inline' => true],
                    ['name' => 'Days Remaining', 'value' => (string) ($this->domainMonitor->days_remaining ?? 'N/A'), 'inline' => true],
                    ['name' => 'Registrar', 'value' => $this->domainMonitor->registrar ?? 'Unknown', 'inline' => true],
                ],
                'timestamp' => now()->toIso8601String(),
            ]],
        ]);
    }

    protected function sendWebhook(NotificationChannel $channel): void
    {
        $url = $channel->config['url'] ?? null;
        if (!$url) {
            return;
        }

        $method = strtolower($channel->config['method'] ?? 'POST');
        $headers = $channel->config['headers'] ?? [];
        $site = $this->domainMonitor->site;

        $payload = [
            'event' => 'domain_alert',
            'alert_type' => $this->alertType,
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
            'domain' => [
                'name' => $this->domainMonitor->domain,
                'registrar' => $this->domainMonitor->registrar,
                'expires_at' => $this->domainMonitor->expires_at?->toIso8601String(),
                'days_remaining' => $this->domainMonitor->days_remaining,
                'status' => $this->domainMonitor->status,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        Http::withHeaders($headers)->$method($url, $payload);
    }
}
