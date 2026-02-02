<?php

namespace App\Jobs;

use App\Mail\SslAlertMail;
use App\Models\NotificationChannel;
use App\Models\SslCertificate;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifySslAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public SslCertificate $certificate,
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

        $this->certificate->update(['last_alert_sent_at' => now()]);
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

        Mail::to($address)->queue(new SslAlertMail($this->certificate, $this->alertType));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $site = $this->certificate->site;
        $color = match ($this->alertType) {
            'expired' => '#DC2626',
            'expiring_soon' => '#EAB308',
            default => '#DC2626',
        };
        $title = match ($this->alertType) {
            'expired' => "SSL EXPIRED: {$site->name}",
            'expiring_soon' => "SSL EXPIRING SOON: {$site->name}",
            default => "SSL ERROR: {$site->name}",
        };

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => $color,
                'title' => $title,
                'fields' => [
                    ['title' => 'Domain', 'value' => $this->certificate->domain, 'short' => true],
                    ['title' => 'Days Remaining', 'value' => (string) ($this->certificate->days_remaining ?? 'N/A'), 'short' => true],
                    ['title' => 'Issuer', 'value' => $this->certificate->issuer ?? 'Unknown', 'short' => true],
                    ['title' => 'Expires', 'value' => $this->certificate->expires_at?->format('M d, Y') ?? 'Unknown', 'short' => true],
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

        $site = $this->certificate->site;
        $color = match ($this->alertType) {
            'expired' => 0xDC2626,
            'expiring_soon' => 0xEAB308,
            default => 0xDC2626,
        };

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => match ($this->alertType) {
                    'expired' => "SSL EXPIRED: {$site->name}",
                    'expiring_soon' => "SSL EXPIRING SOON: {$site->name}",
                    default => "SSL ERROR: {$site->name}",
                },
                'color' => $color,
                'fields' => [
                    ['name' => 'Domain', 'value' => $this->certificate->domain, 'inline' => true],
                    ['name' => 'Days Remaining', 'value' => (string) ($this->certificate->days_remaining ?? 'N/A'), 'inline' => true],
                    ['name' => 'Issuer', 'value' => $this->certificate->issuer ?? 'Unknown', 'inline' => true],
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
        $site = $this->certificate->site;

        $payload = [
            'event' => 'ssl_alert',
            'alert_type' => $this->alertType,
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
            'certificate' => [
                'domain' => $this->certificate->domain,
                'issuer' => $this->certificate->issuer,
                'expires_at' => $this->certificate->expires_at?->toIso8601String(),
                'days_remaining' => $this->certificate->days_remaining,
                'status' => $this->certificate->status,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        Http::withHeaders($headers)->$method($url, $payload);
    }
}
