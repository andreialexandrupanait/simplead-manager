<?php

namespace App\Jobs;

use App\Mail\BrokenLinksAlertMail;
use App\Models\LinkMonitor;
use App\Models\LinkScan;
use App\Models\NotificationChannel;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifyBrokenLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public LinkMonitor $monitor,
        public LinkScan $scan,
        public int $brokenCount,
    ) {}

    public function handle(SettingsService $settings): void
    {
        // Check quiet hours
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

        Mail::to($address)->queue(new BrokenLinksAlertMail($this->monitor, $this->scan, $this->brokenCount));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $site = $this->monitor->site;

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => '#DC2626',
                'title' => "BROKEN LINKS: {$this->brokenCount} found on {$site->name}",
                'fields' => [
                    ['title' => 'Site', 'value' => $site->name, 'short' => true],
                    ['title' => 'Broken Links', 'value' => (string) $this->brokenCount, 'short' => true],
                    ['title' => 'Total Links', 'value' => (string) $this->scan->total_links, 'short' => true],
                    ['title' => 'Pages Scanned', 'value' => (string) $this->scan->pages_scanned, 'short' => true],
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

        $site = $this->monitor->site;

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => "BROKEN LINKS: {$this->brokenCount} found on {$site->name}",
                'color' => 0xDC2626,
                'fields' => [
                    ['name' => 'Site', 'value' => $site->name, 'inline' => true],
                    ['name' => 'Broken Links', 'value' => (string) $this->brokenCount, 'inline' => true],
                    ['name' => 'Total Links', 'value' => (string) $this->scan->total_links, 'inline' => true],
                    ['name' => 'Pages Scanned', 'value' => (string) $this->scan->pages_scanned, 'inline' => true],
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
        $site = $this->monitor->site;

        $payload = [
            'event' => 'broken_links',
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
            'scan' => [
                'broken_links' => $this->brokenCount,
                'total_links' => $this->scan->total_links,
                'redirects' => $this->scan->redirects,
                'pages_scanned' => $this->scan->pages_scanned,
                'duration_seconds' => $this->scan->duration_seconds,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        Http::withHeaders($headers)->$method($url, $payload);
    }
}
