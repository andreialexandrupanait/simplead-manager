<?php

namespace App\Jobs;

use App\Mail\BudgetViolationMail;
use App\Models\NotificationChannel;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifyBudgetViolation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public PerformanceMonitor $monitor,
        public array $violations,
        public PerformanceTest $test
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

        Mail::to($address)->queue(new BudgetViolationMail(
            $this->monitor,
            $this->violations,
            $this->test
        ));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        $site = $this->monitor->site;
        $violationLines = [];
        foreach ($this->violations as $v) {
            $violationLines[] = "• {$v['key']}: {$v['actual']} (budget: {$v['budget']})";
        }

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => '#EF4444',
                'title' => "BUDGET EXCEEDED: {$site->name}",
                'text' => implode("\n", $violationLines),
                'fields' => [
                    ['title' => 'Site', 'value' => $site->name, 'short' => true],
                    ['title' => 'Violations', 'value' => (string) count($this->violations), 'short' => true],
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
        $violationLines = [];
        foreach ($this->violations as $v) {
            $violationLines[] = "• **{$v['key']}**: {$v['actual']} (budget: {$v['budget']})";
        }

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => "BUDGET EXCEEDED: {$site->name}",
                'color' => 0xEF4444,
                'description' => implode("\n", $violationLines),
                'fields' => [
                    ['name' => 'Site', 'value' => $site->name, 'inline' => true],
                    ['name' => 'Violations', 'value' => (string) count($this->violations), 'inline' => true],
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
            'event' => 'budget_violation',
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
            'violations' => array_values($this->violations),
            'test_id' => $this->test->id,
            'timestamp' => now()->toIso8601String(),
        ];

        Http::withHeaders($headers)->$method($url, $payload);
    }
}
