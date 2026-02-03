<?php

namespace App\Jobs;

use App\Mail\UptimeAlertMail;
use App\Models\NotificationChannel;
use App\Models\UptimeIncident;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifyIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public UptimeIncident $incident,
        public string $type // 'down' or 'recovery'
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

        // Track which channels were notified
        $notifiedVia = $this->incident->notified_via ?? [];
        $notifiedVia = array_merge($notifiedVia, $channels->pluck('type')->toArray());

        $this->incident->update([
            'notified_via' => array_unique($notifiedVia),
            'notified_at' => now(),
        ]);
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

        // Wraps past midnight
        return $now >= $start || $now <= $end;
    }

    protected function resolveChannels()
    {
        $monitor = $this->incident->monitor;

        // Use monitor-specific contacts if defined
        if ($monitor->alert_contacts && count($monitor->alert_contacts) > 0) {
            return NotificationChannel::whereIn('id', $monitor->alert_contacts)->get();
        }

        // Fall back to default channels
        return NotificationChannel::where('is_default', true)->where('is_active', true)->get();
    }

    protected function sendEmail(NotificationChannel $channel): void
    {
        $address = $channel->config['address'] ?? null;
        if (!$address) {
            return;
        }

        Mail::to($address)->queue(new UptimeAlertMail($this->incident, $this->type));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $monitor = $this->incident->monitor;
        $site = $monitor->site;
        $color = $this->type === 'down' ? '#DC2626' : '#16A34A';
        $title = $this->type === 'down'
            ? "DOWN: {$site->name}"
            : "RECOVERED: {$site->name}";

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => $color,
                'title' => $title,
                'fields' => [
                    ['title' => 'URL', 'value' => $monitor->url, 'short' => true],
                    ['title' => 'Cause', 'value' => $this->incident->cause ?? 'Unknown', 'short' => true],
                    ['title' => 'Duration', 'value' => $this->incident->duration, 'short' => true],
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

        $monitor = $this->incident->monitor;
        $site = $monitor->site;
        $color = $this->type === 'down' ? 0xDC2626 : 0x16A34A;

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => $this->type === 'down'
                    ? "DOWN: {$site->name}"
                    : "RECOVERED: {$site->name}",
                'color' => $color,
                'fields' => [
                    ['name' => 'URL', 'value' => $monitor->url, 'inline' => true],
                    ['name' => 'Cause', 'value' => $this->incident->cause ?? 'Unknown', 'inline' => true],
                    ['name' => 'Duration', 'value' => $this->incident->duration, 'inline' => true],
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

        $monitor = $this->incident->monitor;
        $site = $monitor->site;

        $payload = [
            'event' => $this->type,
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
            'monitor' => [
                'url' => $monitor->url,
                'type' => $monitor->type,
            ],
            'incident' => [
                'cause' => $this->incident->cause,
                'started_at' => $this->incident->started_at->toIso8601String(),
                'resolved_at' => $this->incident->resolved_at?->toIso8601String(),
                'duration' => $this->incident->duration,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        Http::withHeaders($headers)->$method($url, $payload);
    }
}
