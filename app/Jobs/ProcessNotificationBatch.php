<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Models\Site;
use App\Support\ReliableRedisList;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

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

    /**
     * Bounded per-run item cap so a huge incident-storm buffer drains across
     * several minute-cadence runs rather than in one over-long pass.
     */
    private const MAX_ITEMS = 1000;

    /**
     * Explicit timeout (the notifications supervisor runs a tight worker timeout);
     * combined with the at-least-once drain, a mid-batch kill no longer loses
     * items — they stay claimed on the processing list and are recovered next run.
     */
    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        // P1-54: at-least-once drain. Items are reserved onto a processing list and
        // only removed once safely handed to the durable queue — a SIGKILL mid-loop
        // leaves the remainder claimed for recovery instead of dropping them.
        $reserved = ReliableRedisList::reserve(self::BUFFER_KEY, self::MAX_ITEMS);
        if ($reserved === []) {
            return;
        }

        // Decode + validate. Malformed items (older code version / corrupted JSON,
        // e.g. prod's "Undefined array key channel_id") are dropped immediately —
        // acked so they never re-enter the processing loop.
        $items = []; // list<array{raw:string,data:array}>
        foreach ($reserved as $raw) {
            $data = json_decode($raw, true);
            if (! is_array($data) || ! isset($data['channel_id'], $data['event'])) {
                Log::warning('Skipping malformed notification buffer item', ['item' => $data]);
                ReliableRedisList::ack(self::BUFFER_KEY, $raw);

                continue;
            }
            $items[] = ['raw' => $raw, 'data' => $data];
        }

        // Group by channel_id + event
        $groups = [];
        foreach ($items as $entry) {
            $key = $entry['data']['channel_id'].':'.$entry['data']['event'];
            $groups[$key][] = $entry;
        }

        foreach ($groups as $entries) {
            $groupData = array_map(static fn (array $e): array => $e['data'], $entries);
            $first = $groupData[0];

            $channel = NotificationChannel::find($first['channel_id']);
            if (! $channel || ! $channel->is_active) {
                // No deliverable channel — drop (ack) so we don't reprocess forever.
                $this->ackAll($entries);

                continue;
            }

            // Hand off to the durable queue BEFORE acking. If dispatch throws (queue
            // unavailable), the exception propagates and the still-claimed items are
            // recovered on the next run — never silently lost.
            if (count($groupData) === 1) {
                $this->dispatchSingle($channel, $first);
            } else {
                $this->dispatchGrouped($channel, $groupData);
            }

            $this->ackAll($entries);
        }
    }

    /**
     * @param  list<array{raw:string,data:array}>  $entries
     */
    private function ackAll(array $entries): void
    {
        foreach ($entries as $entry) {
            ReliableRedisList::ack(self::BUFFER_KEY, $entry['raw']);
        }
    }

    private function dispatchSingle(NotificationChannel $channel, array $first): void
    {
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
