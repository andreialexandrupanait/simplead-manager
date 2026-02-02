<?php

namespace App\Jobs;

use App\Mail\BackupAlertMail;
use App\Models\Backup;
use App\Models\NotificationChannel;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotifyBackupFailed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Site $site,
        public Backup $backup,
        public string $errorMessage,
    ) {}

    public function handle(): void
    {
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

    protected function resolveChannels()
    {
        return NotificationChannel::where('is_default', true)
            ->where('is_active', true)
            ->get();
    }

    protected function sendEmail(NotificationChannel $channel): void
    {
        $address = $channel->config['address'] ?? null;
        if (!$address) {
            return;
        }

        Mail::to($address)->queue(new BackupAlertMail($this->site, $this->backup, $this->errorMessage));
    }

    protected function sendSlack(NotificationChannel $channel): void
    {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return;
        }

        Http::post($webhookUrl, [
            'attachments' => [[
                'color' => '#DC2626',
                'title' => "BACKUP FAILED: {$this->site->name}",
                'fields' => [
                    ['title' => 'Site', 'value' => $this->site->name, 'short' => true],
                    ['title' => 'URL', 'value' => $this->site->url, 'short' => true],
                    ['title' => 'Type', 'value' => $this->backup->type, 'short' => true],
                    ['title' => 'Error', 'value' => $this->errorMessage, 'short' => false],
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

        Http::post($webhookUrl, [
            'embeds' => [[
                'title' => "BACKUP FAILED: {$this->site->name}",
                'color' => 0xDC2626,
                'fields' => [
                    ['name' => 'Site', 'value' => $this->site->name, 'inline' => true],
                    ['name' => 'URL', 'value' => $this->site->url, 'inline' => true],
                    ['name' => 'Type', 'value' => $this->backup->type, 'inline' => true],
                    ['name' => 'Error', 'value' => $this->errorMessage, 'inline' => false],
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

        Http::withHeaders($headers)->$method($url, [
            'event' => 'backup_failed',
            'site' => [
                'name' => $this->site->name,
                'url' => $this->site->url,
            ],
            'backup' => [
                'id' => $this->backup->id,
                'type' => $this->backup->type,
                'trigger' => $this->backup->trigger,
            ],
            'error' => $this->errorMessage,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
