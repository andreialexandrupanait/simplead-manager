<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Services\SettingsService;
use Illuminate\Contracts\Mail\Mailable;

class NotificationService
{
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

        // Resolve channels
        if ($channelIds) {
            $channels = NotificationChannel::whereIn('id', $channelIds)->where('is_active', true)->get();
        } else {
            $channels = NotificationChannel::where('is_default', true)->where('is_active', true)->get();
        }

        foreach ($channels as $channel) {
            if (!$channel->subscribedTo($event)) {
                continue;
            }

            SendNotificationJob::dispatch(
                $channel,
                $site,
                $event,
                $title,
                $message,
                $fields,
                $severity,
                $webhookPayload,
                $mailableClass,
                $mailableArgs,
            );
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

        if ($channelIds) {
            $channels = NotificationChannel::whereIn('id', $channelIds)->where('is_active', true)->get();
        } else {
            $channels = NotificationChannel::where('is_default', true)->where('is_active', true)->get();
        }

        foreach ($channels as $channel) {
            if (!$channel->subscribedTo($event)) {
                continue;
            }

            SendNotificationJob::dispatch(
                $channel,
                null,
                $event,
                $title,
                $message,
                $fields,
                $severity,
                $webhookPayload,
                $mailableClass,
                $mailableArgs,
            );
        }
    }

    protected static function isQuietHours(): bool
    {
        $settings = app(SettingsService::class);

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
}
