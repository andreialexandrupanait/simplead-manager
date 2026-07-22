<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\NotificationEventPreference;
use App\Models\NotificationTemplate;
use App\Models\Site;
use App\Services\SettingsService;
use App\Support\ReliableRedisList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NotificationService
{
    /**
     * Deduplication window in seconds. Same event+site won't be re-sent within this window.
     */
    private const DEDUP_WINDOW = 300; // 5 minutes

    /**
     * Redis key for the notification buffer used by ProcessNotificationBatch.
     */
    private const BUFFER_KEY = 'notification_buffer';

    /**
     * Buffer TTL — if ProcessNotificationBatch doesn't run, items auto-expire.
     */
    private const BUFFER_TTL = 300; // 5 minutes

    /**
     * Redis key holding channel sends deferred by quiet hours (P1-21). Drained by
     * FlushDeferredNotifications once quiet hours end, so non-critical alerts are
     * delayed — never silently annihilated.
     */
    private const DEFERRED_KEY = 'notification_deferred';

    /**
     * Deferred-queue TTL. Long enough to span a full quiet-hours window.
     */
    private const DEFERRED_TTL = 86400; // 24 hours

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
        // P1-21: during quiet hours, non-critical channel sends are DEFERRED (not
        // dropped) and the in-app record is still written below — nothing is lost.
        $deferChannels = $severity !== 'critical' && static::isQuietHours();

        // Deduplication — skip if same event+site+severity was sent recently
        if (static::isDuplicate($event, $site->id, $severity)) {
            return;
        }

        // Apply notification template if configured
        $template = NotificationTemplate::where('event', $event)->where('is_active', true)->first();
        if ($template) {
            $title = $template->renderTitle($site, $title, ['severity' => $severity, 'details' => $message]);
            $message = $template->renderMessage($site, $message, ['severity' => $severity, 'details' => $message]);
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

            // Check per-event per-channel preference for the site owner
            if (! static::isEventEnabledForChannel($site->user_id, $channel->id, $event)) {
                continue;
            }

            if ($deferChannels) {
                // Quiet hours — hold the channel send until they end (P1-21).
                static::defer($channel, $site, $event, $title, $message, $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs);
            } elseif ($severity === 'info' || static::shouldAggregate($event)) {
                // Buffer info notifications, and — when alert-storm aggregation is on
                // (C-11) — site_down/site_recovered too, so ProcessNotificationBatch
                // coalesces a cross-site burst into one "Nx" message per channel
                // instead of one send per site.
                static::buffer($channel, $site, $event, $title, $message, $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs);
            } else {
                SendNotificationJob::dispatch(
                    $channel, $site, $event, $title, $message,
                    $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
                );
            }
        }

        // Create in-app notification for the site owner.
        // When title is empty (slim format), derive a clean in-app title from the message.
        try {
            [$inAppTitle, $inAppMessage] = static::deriveInAppText($title, $message);

            \App\Models\InAppNotification::create([
                'user_id' => $site->user_id,
                'type' => $severity,
                'title' => $inAppTitle,
                'message' => $inAppMessage,
                'data' => ['event' => $event, 'site_id' => $site->id, 'site_name' => $site->name, 'fields' => $fields],
            ]);
        } catch (\Throwable) {
            // Don't fail the notification if in-app creation fails
        }
    }

    /**
     * Strip Slack mrkdwn markers so in-app notifications stay readable.
     * When the slim format is in use, `$title` is empty and `$message` contains
     * the full mrkdwn one-liner — split it into a plain title and any deep-link.
     */
    protected static function deriveInAppText(string $title, string $message): array
    {
        if ($title !== '') {
            return [$title, $message];
        }

        $lines = preg_split('/\r?\n/', $message) ?: [];
        $first = $lines[0] ?? '';
        $rest = array_slice($lines, 1);

        $plain = static fn (string $s): string => trim(preg_replace(
            ['/<([^|>]+)\|([^>]+)>/', '/[*_`]/'],
            ['$2', ''],
            $s,
        ) ?? $s);

        return [$plain($first), implode("\n", array_map($plain, $rest))];
    }

    /**
     * Slim notification: a single mrkdwn one-liner plus an optional deep-link.
     * Use this in place of notifySiteEvent() — callers pass pre-formatted text
     * and the Slack sender renders it without separate title/fields.
     */
    public static function notifySiteEventSlim(
        Site $site,
        string $event,
        string $summary,
        ?string $deepLink = null,
        string $severity = 'warning',
        ?array $webhookPayload = null,
        ?string $mailableClass = null,
        ?array $mailableArgs = null,
        ?array $channelIds = null,
    ): void {
        $message = $deepLink !== null && $deepLink !== ''
            ? $summary."\n".$deepLink
            : $summary;

        static::notifySiteEvent(
            site: $site,
            event: $event,
            title: '',
            message: $message,
            fields: [],
            severity: $severity,
            webhookPayload: $webhookPayload,
            mailableClass: $mailableClass,
            mailableArgs: $mailableArgs,
            channelIds: $channelIds,
        );
    }

    /**
     * Slim variant of notifyAppEvent — no site context.
     */
    public static function notifyAppEventSlim(
        string $event,
        string $summary,
        ?string $deepLink = null,
        string $severity = 'warning',
        ?array $webhookPayload = null,
        ?string $mailableClass = null,
        ?array $mailableArgs = null,
        ?array $channelIds = null,
    ): void {
        $message = $deepLink !== null && $deepLink !== ''
            ? $summary."\n".$deepLink
            : $summary;

        static::notifyAppEvent(
            event: $event,
            title: '',
            message: $message,
            fields: [],
            severity: $severity,
            webhookPayload: $webhookPayload,
            mailableClass: $mailableClass,
            mailableArgs: $mailableArgs,
            channelIds: $channelIds,
        );
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
        ?array $channelIds = null,
        bool $sync = false
    ): void {
        // P1-21: defer non-critical app-event channel sends during quiet hours
        // instead of dropping them. Sync meta-alerts (e.g. "Horizon is down") are
        // typically critical and are never deferred.
        $deferChannels = $severity !== 'critical' && ! $sync && static::isQuietHours();

        // Deduplication — skip if same app event+severity was sent recently
        if (static::isDuplicate($event, null, $severity)) {
            return;
        }

        if ($channelIds) {
            $channels = NotificationChannel::whereIn('id', $channelIds)->where('is_active', true)->get();
        } else {
            $channels = NotificationChannel::where('is_default', true)->where('is_active', true)->get();
        }

        $authUserId = auth()->id();

        foreach ($channels as $channel) {
            if (! $channel->subscribedTo($event)) {
                continue;
            }

            // Check per-event per-channel preference for the authenticated user (if available)
            if ($authUserId !== null && ! static::isEventEnabledForChannel($authUserId, $channel->id, $event)) {
                continue;
            }

            if ($deferChannels) {
                // Quiet hours — hold the channel send until they end (P1-21).
                static::defer($channel, null, $event, $title, $message, $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs);
            } elseif ($severity === 'info') {
                // Only buffer info-level notifications; everything else dispatches immediately
                static::buffer($channel, null, $event, $title, $message, $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs);
            } elseif ($sync) {
                // Meta-alerts (e.g. "Horizon is down") must not ride the queue
                // Horizon itself processes — run inline so they send even when
                // the worker fleet is dead.
                SendNotificationJob::dispatchSync(
                    $channel, null, $event, $title, $message,
                    $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
                );
            } else {
                SendNotificationJob::dispatch(
                    $channel, null, $event, $title, $message,
                    $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
                );
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
     * P1-21: hold a channel send in the deferred Redis list during quiet hours.
     * FlushDeferredNotifications drains it once quiet hours end. Falls back to
     * immediate dispatch if Redis is unavailable — a delayed alert is acceptable,
     * a lost one is not.
     */
    protected static function defer(
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
                'deferred_at' => now()->toIso8601String(),
            ];

            Redis::rpush(self::DEFERRED_KEY, json_encode($item));
            Redis::expire(self::DEFERRED_KEY, self::DEFERRED_TTL);
        } catch (\Throwable $e) {
            SendNotificationJob::dispatch(
                $channel, $site, $event, $title, $message,
                $fields, $severity, $webhookPayload, $mailableClass, $mailableArgs,
            );
        }
    }

    /**
     * Drain quiet-hours-deferred notifications once quiet hours are over. Called
     * every minute by FlushDeferredNotifications. Returns the number of sends
     * dispatched. Uses the at-least-once reliable-list pattern so a mid-drain kill
     * cannot lose held alerts (P1-21 + P1-54).
     */
    public static function flushDeferred(int $max = 1000): int
    {
        // Still inside quiet hours — leave everything queued.
        if (static::isQuietHours()) {
            return 0;
        }

        $reserved = ReliableRedisList::reserve(self::DEFERRED_KEY, $max);
        if ($reserved === []) {
            return 0;
        }

        $dispatched = 0;
        foreach ($reserved as $raw) {
            $item = json_decode($raw, true);

            if (! is_array($item) || ! isset($item['channel_id'], $item['event'])) {
                Log::warning('Skipping malformed deferred notification item', ['item' => $item]);
                ReliableRedisList::ack(self::DEFERRED_KEY, $raw);

                continue;
            }

            $channel = NotificationChannel::find($item['channel_id']);
            if (! $channel || ! $channel->is_active) {
                ReliableRedisList::ack(self::DEFERRED_KEY, $raw);

                continue;
            }

            $site = ! empty($item['site_id']) ? Site::find($item['site_id']) : null;

            // Hand off to the durable queue BEFORE acking (at-least-once).
            SendNotificationJob::dispatch(
                $channel,
                $site,
                $item['event'],
                $item['title'] ?? '',
                $item['message'] ?? '',
                $item['fields'] ?? [],
                $item['severity'] ?? 'warning',
                $item['webhook_payload'] ?? null,
                $item['mailable_class'] ?? null,
                $item['mailable_args'] ?? null,
            );

            ReliableRedisList::ack(self::DEFERRED_KEY, $raw);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Check per-event per-channel preference for a user.
     * Returns true (send) when no preference row exists (default enabled) or when enabled=true.
     * Returns false (skip) only when an explicit preference exists with enabled=false.
     */
    protected static function isEventEnabledForChannel(int $userId, int $channelId, string $event): bool
    {
        $preference = NotificationEventPreference::where('user_id', $userId)
            ->where('notification_channel_id', $channelId)
            ->where('event', $event)
            ->first();

        if ($preference === null) {
            return true; // No preference row — default to enabled
        }

        return $preference->enabled;
    }

    /**
     * Check if this event+site+severity combination was already sent within the
     * dedup window. Returns true if duplicate (should skip), false if new (send).
     *
     * P2-54: the key now includes severity so genuinely DISTINCT alerts (different
     * site, event or severity) are never collapsed into one another — only a true
     * duplicate (same event + same site + same severity inside the window) is
     * suppressed. The check-and-set is ATOMIC via Cache::add() (write-if-absent,
     * returning whether it wrote), closing the check-then-set race where two
     * concurrent alerts could both pass a has() probe. Suppressions are logged so
     * a swallowed alert is never traceless.
     */
    protected static function isDuplicate(string $event, ?int $siteId = null, string $severity = 'warning'): bool
    {
        $key = 'notification_dedup:'.$event.':'.($siteId ?? 'app').':'.$severity;

        // Atomic: add() only writes when the key is absent and returns true iff it
        // did. A false return means another call already claimed this window — so
        // THIS call is the duplicate.
        if (Cache::add($key, true, self::DEDUP_WINDOW)) {
            return false;
        }

        Log::debug('Suppressed duplicate notification within dedup window', [
            'event' => $event,
            'site_id' => $siteId,
            'severity' => $severity,
            'window_seconds' => self::DEDUP_WINDOW,
        ]);

        return true;
    }

    /**
     * C-11: whether a cross-site down/recovery burst should be coalesced through
     * the batch buffer instead of dispatched one-per-site. Only the two uptime
     * storm events qualify, and only while aggregation is enabled.
     */
    protected static function shouldAggregate(string $event): bool
    {
        return in_array($event, ['site_down', 'site_recovered'], true)
            && (bool) config('monitoring.aggregate_alert_storms', true);
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
