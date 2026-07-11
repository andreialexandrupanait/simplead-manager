<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Processes buffered notifications from Redis, groups them by event+channel,
 * and dispatches consolidated SendNotificationJob instances.
 *
 * Runs every minute via the scheduler.
 */
class ProcessNotificationBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const BUFFER_KEY = 'notification_buffer';

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        // Atomically pop all buffered items
        $items = [];
        while ($raw = Redis::lpop(self::BUFFER_KEY)) {
            $item = json_decode($raw, true);
            if ($item) {
                $items[] = $item;
            }
        }

        if (empty($items)) {
            return;
        }

        // Group by channel_id + event
        $groups = [];
        foreach ($items as $item) {
            // Buffer items written by an older code version (or corrupted JSON)
            // may lack required keys — skip them instead of crashing the whole
            // batch (seen in prod: "Undefined array key channel_id").
            if (! is_array($item) || ! isset($item['channel_id'], $item['event'])) {
                Log::warning('Skipping malformed notification buffer item', ['item' => $item]);

                continue;
            }

            $key = $item['channel_id'].':'.$item['event'];
            $groups[$key][] = $item;
        }

        foreach ($groups as $groupItems) {
            $first = $groupItems[0];
            $channel = NotificationChannel::find($first['channel_id']);
            if (! $channel || ! $channel->is_active) {
                continue;
            }

            $count = count($groupItems);

            if ($count === 1) {
                // Single notification — send as-is
                $site = $first['site_id'] ? Site::find($first['site_id']) : null;

                SendNotificationJob::dispatch(
                    $channel,
                    $site,
                    $first['event'],
                    $first['title'] ?? '',
                    $first['message'] ?? '',
                    $first['fields'] ?? [],
                    $first['severity'] ?? 'warning',
                    $first['webhook_payload'] ?? null,
                    $first['mailable_class'] ?? null,
                    $first['mailable_args'] ?? null,
                );
            } else {
                // Multiple notifications — send grouped summary
                $this->dispatchGrouped($channel, $groupItems);
            }
        }
    }

    private function dispatchGrouped(NotificationChannel $channel, array $items): void
    {
        $count = count($items);
        $first = $items[0];
        $event = $first['event'];
        $severity = $this->highestSeverity($items);

        // Collect unique site names
        $siteIds = array_unique(array_filter(array_column($items, 'site_id')));
        $siteNames = Site::whereIn('id', $siteIds)->pluck('name')->toArray();

        $title = "{$count}x {$first['title']}";
        $sitesLabel = count($siteNames) <= 5
            ? implode(', ', $siteNames)
            : implode(', ', array_slice($siteNames, 0, 5)).' +'.(count($siteNames) - 5).' more';

        $message = "{$count} occurrences in the last minute";
        if (! empty($sitesLabel)) {
            $message .= "\nAffected sites: {$sitesLabel}";
        }

        $fields = [
            ['title' => 'Event', 'value' => $event],
            ['title' => 'Count', 'value' => (string) $count],
        ];

        if (! empty($siteNames)) {
            $fields[] = ['title' => 'Sites', 'value' => $sitesLabel];
        }

        // For webhooks, include all individual payloads
        $webhookPayload = [
            'grouped' => true,
            'count' => $count,
            'event' => $event,
            'site_ids' => $siteIds,
            'items' => array_map(fn ($i) => [
                'site_id' => $i['site_id'],
                'title' => $i['title'],
                'message' => $i['message'],
                'severity' => $i['severity'] ?? 'warning',
            ], $items),
        ];

        SendNotificationJob::dispatch(
            $channel,
            null, // grouped — no single site
            $event,
            $title,
            $message,
            $fields,
            $severity,
            $webhookPayload,
            null, // no mailable for grouped
            null,
        );
    }

    private function highestSeverity(array $items): string
    {
        $priority = ['critical' => 3, 'warning' => 2, 'success' => 1, 'info' => 0];
        $highest = 'info';

        foreach ($items as $item) {
            $sev = $item['severity'] ?? 'warning';
            if (($priority[$sev] ?? 0) > ($priority[$highest] ?? 0)) {
                $highest = $sev;
            }
        }

        return $highest;
    }
}
