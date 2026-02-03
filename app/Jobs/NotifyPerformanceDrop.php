<?php

namespace App\Jobs;

use App\Mail\PerformanceAlertMail;
use App\Models\NotificationChannel;
use App\Models\PerformanceMonitor;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifyPerformanceDrop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public PerformanceMonitor $monitor,
        public string $device,
        public int $previousScore,
        public int $currentScore
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

        Mail::to($address)->queue(new PerformanceAlertMail(
            $this->monitor,
            $this->device,
            $this->previousScore,
            $this->currentScore
        ));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $site = $this->monitor->site;
        $drop = $this->previousScore - $this->currentScore;

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => '#F59E0B',
                'title' => "PERFORMANCE DROP: {$site->name} ({$this->device})",
                'fields' => [
                    ['title' => 'Site', 'value' => $site->name, 'short' => true],
                    ['title' => 'Device', 'value' => ucfirst($this->device), 'short' => true],
                    ['title' => 'Previous Score', 'value' => (string) $this->previousScore, 'short' => true],
                    ['title' => 'Current Score', 'value' => (string) $this->currentScore, 'short' => true],
                    ['title' => 'Drop', 'value' => "-{$drop} points", 'short' => true],
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
        $drop = $this->previousScore - $this->currentScore;

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => "PERFORMANCE DROP: {$site->name} ({$this->device})",
                'color' => 0xF59E0B,
                'fields' => [
                    ['name' => 'Site', 'value' => $site->name, 'inline' => true],
                    ['name' => 'Device', 'value' => ucfirst($this->device), 'inline' => true],
                    ['name' => 'Score', 'value' => "{$this->previousScore} → {$this->currentScore} (-{$drop})", 'inline' => true],
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
            'event' => 'performance_drop',
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
            'device' => $this->device,
            'previous_score' => $this->previousScore,
            'current_score' => $this->currentScore,
            'drop' => $this->previousScore - $this->currentScore,
            'timestamp' => now()->toIso8601String(),
        ];

        Http::withHeaders($headers)->$method($url, $payload);
    }
}
