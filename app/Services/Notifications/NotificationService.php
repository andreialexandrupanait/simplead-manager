<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class NotificationService
{
    /**
     * Deduplication window in seconds. Same event+site won't be re-sent within this window.
     */
    private const DEDUP_WINDOW = 1800; // 30 minutes

    /**
     * Redis key for the notification buffer used by ProcessNotificationBatch.
     */
    private const BUFFER_KEY = 'notification_buffer';

    /**
     * Buffer TTL — if ProcessNotificationBatch doesn't run, items auto-expire.
     */
    private const BUFFER_TTL = 300; // 5 minutes

    public static function notifySiteEvent(
        Site $site,
        string $event,
        string $title,
        string $message,
        array $fields = [],
        string $severity = 'warning',
        ?array $webhookPayload = null,
        ?string $mailableClass = null,
        ?array $mailableArgs = null,
        ?array $channelIds = null
    ): void {
        // Check quiet hours — skip non-critical notifications
        if ($severity !== 'critical' && static::isQuietHours()) {
            return;
        }

        // Deduplication — skip if same event+site was sent recently
        if (static::isDuplicate($event, $site->id)) {
            return;
        }

        // Resolve channels
        if ($channelIds) {
            $channels = NotificationChannel::whereIn('id', $channelIds)->where('is_active', true)->get();
        } else {
            $channels = NotificationChannel::where('is_default', true)->where('is_active', true)->get();
        }

        foreach ($channels as $channel) {
            if (! $channel->subscribedTo($event)) {
                continue;
            }

            // Critical notifications bypass the buffer
            if ($severity === 'critical') {
                SendNotificationJob::dispatch(
                    $channel, $site, $event, $title, $message,
                    $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
                );
            } else {
                static::buffer($channel, $site, $event, $title, $message, $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs);
            }
        }
    }

    public static function notifyAppEvent(
        string $event,
        string $title,
        string $message,
        array $fields = [],
        string $severity = 'warning',
        ?array $webhookPayload = null,
        ?string $mailableClass = null,
        ?array $mailableArgs = null,
        ?array $channelIds = null
    ): void {
        if ($severity !== 'critical' && static::isQuietHours()) {
            return;
        }

        // Deduplication — skip if same app event was sent recently
        if (static::isDuplicate($event)) {
            return;
        }

        if ($channelIds) {
            $channels = NotificationChannel::whereIn('id', $channelIds)->where('is_active', true)->get();
        } else {
            $channels = NotificationChannel::where('is_default', true)->where('is_active', true)->get();
        }

        foreach ($channels as $channel) {
            if (! $channel->subscribedTo($event)) {
                continue;
            }

            // Critical & app-wide notifications bypass the buffer
            if ($severity === 'critical') {
                SendNotificationJob::dispatch(
                    $channel, null, $event, $title, $message,
                    $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
                );
            } else {
                static::buffer($channel, null, $event, $title, $message, $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs);
            }
        }
    }

    /**
     * Push a notification into the Redis buffer for batch processing.
     * Falls back to immediate dispatch if Redis is unavailable.
     */
    protected static function buffer(
        NotificationChannel $channel,
        ?Site $site,
        string $event,
        string $title,
        string $message,
        array $fields,
        string $severity,
        ?array $webhookPayload,
        ?string $mailableClass,
        ?array $mailableArgs,
    ): void {
        try {
            $item = [
                'channel_id' => $channel->id,
                'site_id' => $site?->id,
                'event' => $event,
                'title' => $title,
                'message' => $message,
                'fields' => $fields,
                'severity' => $severity,
                'webhook_payload' => $webhookPayload,
                'mailable_class' => $mailableClass,
                'mailable_args' => $mailableArgs,
                'buffered_at' => now()->toIso8601String(),
            ];

            Redis::rpush(self::BUFFER_KEY, json_encode($item));
            Redis::expire(self::BUFFER_KEY, self::BUFFER_TTL);
        } catch (\Throwable $e) {
            // Redis unavailable — fall back to immediate dispatch
            SendNotificationJob::dispatch(
                $channel, $site, $event, $title, $message,
                $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
            );
        }
    }

    /**
     * Check if this event+site combination was already sent within the dedup window.
     * Returns true if duplicate (should skip), false if new (should send).
     */
    protected static function isDuplicate(string $event, ?int $siteId = null): bool
    {
        $key = 'notification_dedup:'.$event.':'.($siteId ?? 'app');

        if (Cache::has($key)) {
            return true;
        }

        Cache::put($key, true, self::DEDUP_WINDOW);

        return false;
    }

    protected static function isQuietHours(): bool
    {
        $settings = app(SettingsService::class);

        $enabled = $settings->get('quiet_hours_enabled', false);
        if (! $enabled) {
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
}
