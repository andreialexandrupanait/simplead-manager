<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * At-least-once draining of a Redis list.
 *
 * Naive `LPOP … dispatch` is at-most-once: if the worker is killed after the pop
 * but before the item is safely handed to a durable queue, the item is gone
 * (P1-54). This helper instead atomically moves items onto a sibling
 * `<key>:processing` list (via RPOPLPUSH) and only removes them once the caller
 * confirms a successful hand-off with {@see ack()}. Anything still in the
 * processing list when a later run starts is recovered — so a mid-batch SIGKILL
 * never loses notifications; the worst case is a duplicate delivery.
 */
final class ReliableRedisList
{
    public static function processingKey(string $key): string
    {
        return $key.':processing';
    }

    /**
     * Recover anything a previously-crashed run left in the processing list, then
     * reserve up to $max items from $key onto the processing list and return their
     * raw payloads. Reserved items remain claimed until {@see ack()} removes them.
     *
     * @return list<string>
     */
    public static function reserve(string $key, int $max = 1000): array
    {
        $processing = self::processingKey($key);

        // Recover orphans from a crashed run back onto the source list so they are
        // reprocessed this pass.
        while (true) {
            $recovered = Redis::rpoplpush($processing, $key);
            if (! is_string($recovered) || $recovered === '') {
                break;
            }
        }

        $reserved = [];
        for ($i = 0; $i < $max; $i++) {
            $raw = Redis::rpoplpush($key, $processing);
            if (! is_string($raw) || $raw === '') {
                break;
            }
            $reserved[] = $raw;
        }

        return $reserved;
    }

    /**
     * Remove a payload from the processing list after it has been safely handed to
     * a durable queue (or deliberately dropped). phpredis signature: (key, value, count).
     */
    public static function ack(string $key, string $raw): void
    {
        Redis::lrem(self::processingKey($key), $raw, 1);
    }
}
