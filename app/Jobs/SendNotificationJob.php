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
use Illuminate\Support\Str;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [5, 15, 60];

    /**
     * Stable acknowledgement token, generated once at construction so the token
     * embedded in the outgoing message always matches the persisted row — even
     * across retries (see $idempotencyKey below). Null for non-ackable severities.
     */
    public ?string $ackToken = null;

    /**
     * P1-20: one logical send == one notification_logs row. This key is generated
     * once at construction and preserved through serialization, so every retry of
     * the same job upserts the same row instead of appending a fresh `failed` row
     * that would later fire a phantom "Delivery FAILED" escalation.
     */
    public string $idempotencyKey;

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
        public bool $isEscalation = false,
    ) {
        $this->onQueue('notifications');
        $this->ackToken = in_array($this->severity, ['critical', 'warning'], true)
            ? Str::random(64)
            : null;
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function handle(): void
    {
        // Ack token was generated at construction so the acknowledgement link can
        // be embedded in the outgoing message (audit N-P1-2: tokens were generated
        // but never delivered, making acknowledgement impossible).
        $ackToken = $this->ackToken;

        $message = $this->message;
        $webhookPayload = $this->webhookPayload ?? [];

        if ($ackToken !== null) {
            $ackUrl = route('notifications.ack', $ackToken);
            $message .= "\n\nAcknowledge: {$ackUrl}";
            $webhookPayload['ack_url'] = $ackUrl;
        }

        $result = match ($this->channel->type) {
            'slack' => SlackNotificationSender::send(
                $this->channel, $this->title, $message, $this->fields, $this->severity
            ),
            'telegram' => TelegramNotificationSender::send(
                $this->channel, $this->title, $message, $this->fields, $this->severity
            ),
            'discord' => DiscordNotificationSender::send(
                $this->channel, $this->title, $message, $this->fields, $this->severity
            ),
            'webhook' => WebhookNotificationSender::send(
                $this->channel, $this->event, $this->site, $webhookPayload
            ),
            'email' => $this->mailableClass
                ? EmailNotificationSender::send($this->channel, $this->mailableClass, $this->mailableArgs ?? [])
                : ['success' => false, 'response_code' => null, 'error' => 'No mailable class provided'],
            default => ['success' => false, 'response_code' => null, 'error' => "Unknown channel type: {$this->channel->type}"],
        };

        // P1-20: upsert on the idempotency key so a retry updates the single row
        // for this logical send (failed → sent on recovery) instead of leaving a
        // stale `failed` row behind that ProcessNotificationEscalations would turn
        // into a false "Delivery FAILED" escalation.
        NotificationLog::updateOrCreate(
            ['idempotency_key' => $this->idempotencyKey],
            [
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
                // Escalation-generated notifications are born "escalated" so
                // ProcessNotificationEscalations never picks them up again —
                // otherwise an A→B + B→A rule pair loops forever (audit N-P1-2).
                'escalated' => $this->isEscalation,
                'metadata' => [
                    'title' => $this->title,
                    'severity' => $this->severity,
                ],
            ]
        );

        // Update channel state
        if ($result['success']) {
            $this->channel->update(['last_used_at' => now()]);
        } else {
            $this->channel->update([
                'last_error' => $result['error'],
                'last_error_at' => now(),
            ]);

            // Always throw: earlier attempts get retried, and the FINAL attempt
            // marks the job as failed instead of silently succeeding (audit
            // N-P1-1: a dead channel lost alerts without a trace). The failed
            // NotificationLog above is still escalated by
            // ProcessNotificationEscalations, which matches failed sends too.
            throw new \RuntimeException('Notification failed: '.($result['error'] ?? 'Unknown error'));
        }
    }
}
