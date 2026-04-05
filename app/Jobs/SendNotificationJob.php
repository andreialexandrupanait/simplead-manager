<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Site;
use App\Services\Notifications\DiscordNotificationSender;
use App\Services\Notifications\EmailNotificationSender;
use App\Services\Notifications\SlackNotificationSender;
use App\Services\Notifications\TelegramNotificationSender;
use App\Services\Notifications\WebhookNotificationSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [5, 15, 60];

    public function __construct(
        public NotificationChannel $channel,
        public ?Site $site,
        public string $event,
        public string $title,
        public string $message,
        public array $fields = [],
        public string $severity = 'warning',
        public ?array $webhookPayload = null,
        public ?string $mailableClass = null,
        public ?array $mailableArgs = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $result = match ($this->channel->type) {
            'slack' => SlackNotificationSender::send(
                $this->channel, $this->title, $this->message, $this->fields, $this->severity
            ),
            'telegram' => TelegramNotificationSender::send(
                $this->channel, $this->title, $this->message, $this->fields, $this->severity
            ),
            'discord' => DiscordNotificationSender::send(
                $this->channel, $this->title, $this->message, $this->fields, $this->severity
            ),
            'webhook' => WebhookNotificationSender::send(
                $this->channel, $this->event, $this->site, $this->webhookPayload ?? []
            ),
            'email' => $this->mailableClass
                ? EmailNotificationSender::send($this->channel, $this->mailableClass, $this->mailableArgs ?? [])
                : ['success' => false, 'response_code' => null, 'error' => 'No mailable class provided'],
            default => ['success' => false, 'response_code' => null, 'error' => "Unknown channel type: {$this->channel->type}"],
        };

        // Log the notification
        $ackToken = in_array($this->severity, ['critical', 'warning']) ? \Illuminate\Support\Str::random(64) : null;

        NotificationLog::create([
            'notification_channel_id' => $this->channel->id,
            'site_id' => $this->site?->id,
            'event' => $this->event,
            'channel_type' => $this->channel->type,
            'status' => $result['success'] ? 'sent' : 'failed',
            'message' => $this->message,
            'error_message' => $result['error'] ?? null,
            'response_code' => $result['response_code'] ?? null,
            'severity' => $this->severity,
            'ack_token' => $ackToken,
            'metadata' => [
                'title' => $this->title,
                'severity' => $this->severity,
            ],
        ]);

        // Update channel state
        if ($result['success']) {
            $this->channel->update(['last_used_at' => now()]);
        } else {
            $this->channel->update([
                'last_error' => $result['error'],
                'last_error_at' => now(),
            ]);

            // Re-throw to trigger retry
            if ($this->attempts() < $this->tries) {
                throw new \RuntimeException('Notification failed: '.($result['error'] ?? 'Unknown error'));
            }
        }
    }
}
