<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPhpErrorLogs implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 180; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'fetch-php-errors-'.$this->site->id;
    }

    public function handle(): void
    {
        if (! $this->site->is_connected) {
            return;
        }

        $api = app(WordPressApiServiceFactory::class)->make($this->site);

        // P2-47: a broken connector endpoint / auth failure throws here
        // (getErrorLogs() calls $response->throw()). Do NOT swallow it — surface
        // the failure so it is visible, then rethrow so the queue records it and
        // failed() logs it at error level. An empty-but-successful fetch (no
        // entries) is a normal success and must NOT be treated as a failure.
        try {
            $result = $api->getErrorLogs(200);
        } catch (\Throwable $e) {
            Log::error("PHP error log fetch failed for site {$this->site->name}: {$e->getMessage()}", [
                'site_id' => $this->site->id,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        $entries = $result['entries'] ?? [];

        $newFatals = 0;

        foreach ($entries as $entry) {
            // Stable dedup key: level + message. The connector re-parses the
            // same rolling window every 6h and returns the SAME aggregated
            // entries each time, so ingestion must be idempotent.
            $hash = md5(($entry['level'] ?? '').($entry['message'] ?? ''));

            $entryLastSeen = $this->parseTimestamp($entry['last_seen'] ?? $entry['timestamp'] ?? null);

            $existing = PhpErrorLog::where('site_id', $this->site->id)
                ->where('message_hash', $hash)
                ->first();

            if ($existing) {
                // P1-51: only advance when the remote reports a strictly newer
                // occurrence. A repeated fetch of the same window is a no-op:
                //  - count is NOT re-added (previously inflated ~4x/day), and
                //  - is_resolved is NOT force-reset (previously resurrected
                //    every 6h). The connector's count is a window total, so we
                //    take the max rather than summing.
                // Compare as naive wall-clock strings so the guard is immune to
                // the container's local timezone (the stored value is naive).
                $entryWall = $entryLastSeen?->format('Y-m-d H:i:s');
                $existingWall = $existing->last_seen_at?->format('Y-m-d H:i:s');

                if ($entryWall === null || $existingWall === null || $entryWall <= $existingWall) {
                    continue;
                }

                $existing->update([
                    'count' => max($existing->count, (int) ($entry['count'] ?? $existing->count)),
                    'last_seen_at' => $entryLastSeen,
                ]);

                continue;
            }

            PhpErrorLog::create([
                'site_id' => $this->site->id,
                'level' => mb_substr($entry['level'] ?? 'unknown', 0, 255),
                'message' => mb_substr($entry['message'] ?? '', 0, 2000),
                // file column is varchar(255): truncate so one long path can't
                // abort the whole batch insert.
                'file' => isset($entry['file']) ? mb_substr((string) $entry['file'], 0, 255) : null,
                'line' => $entry['line'] ?? null,
                'message_hash' => $hash,
                'count' => (int) ($entry['count'] ?? 1),
                'first_seen_at' => $this->parseTimestamp($entry['timestamp'] ?? null) ?? now(),
                'last_seen_at' => $entryLastSeen ?? now(),
            ]);

            if (($entry['level'] ?? '') === 'fatal') {
                $newFatals++;
            }
        }

        if ($newFatals > 0) {
            NotificationService::notifySiteEvent(
                $this->site,
                'php_fatal_error',
                'New PHP Fatal Error(s)',
                "{$newFatals} new fatal error(s) detected on {$this->site->name}.",
                ['Site' => $this->site->name, 'New Fatal Errors' => $newFatals],
                'critical'
            );

            ActivityLogger::log(
                type: 'error_log',
                severity: 'critical',
                title: "{$newFatals} new PHP fatal error(s) detected",
                site: $this->site,
                icon: 'alert-triangle',
            );
        }
    }

    /**
     * P2-47: a genuinely failed fetch (broken endpoint / auth failure) is no longer
     * swallowed — it reaches here so the failure is recorded at error level and is
     * visible in the queue's failed jobs rather than silently disappearing.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("PHP error log fetch failed for site {$this->site->name}", [
            'site_id' => $this->site->id,
            'exception' => $exception ? $exception::class : 'Unknown',
            'message' => $exception?->getMessage(),
        ]);
    }

    /**
     * Parse a connector-supplied timestamp (e.g. "05-Jul-2026 14:30:00 UTC")
     * into a Carbon instance, or null when the value is missing/unparseable.
     * A malformed timestamp must never wedge ingestion — the caller falls back
     * to a safe default and never advances the dedup watermark on garbage.
     */
    private function parseTimestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // Treat a timezone-less value as UTC (the stored watermark is naive);
            // an explicit offset in the string is still honoured by Carbon.
            return \Illuminate\Support\Carbon::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
