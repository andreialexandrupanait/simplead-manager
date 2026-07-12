<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Services\SecurityActivityService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PullSecurityActivityLogs implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Page size requested from the connector. */
    private const PAGE_LIMIT = 500;

    /** Safety cap on pages per run: PAGE_LIMIT * MAX_PAGES events. */
    private const MAX_PAGES = 20;

    public int $tries = 2;

    public int $timeout = 30;

    public int $uniqueFor = 120; // release the lock well after the 30s timeout

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('security');
    }

    public function uniqueId(): string
    {
        return 'pull-security-activity-'.$this->site->id;
    }

    public function handle(): void
    {
        // Watermark = newest already-stored occurred_at. occurred_at is a naive
        // (timezone-less) UTC wall-clock value both in our column and on the
        // connector, so send it verbatim — do NOT tz-convert it (that would shift
        // the cursor by the container's local offset and refetch/skip events).
        $lastLog = SecurityActivityLog::where('site_id', $this->site->id)
            ->orderByDesc('occurred_at')
            ->first();

        $since = $lastLog?->occurred_at?->format('Y-m-d H:i:s');

        $api = app(WordPressApiServiceFactory::class)->make($this->site);
        $service = app(SecurityActivityService::class);

        $totalIngested = 0;
        $page = 0;

        do {
            // order=asc requests forward (oldest-first) cursor pagination so a
            // burst larger than one page is captured page by page (P1-52).
            // Connectors that predate this param simply ignore it and return the
            // newest page; the cursor guard below keeps the loop finite either way.
            $query = ['limit' => self::PAGE_LIMIT, 'order' => 'asc'];
            if ($since !== null) {
                $query['since'] = $since;
            }

            try {
                $response = $api->request('GET', '/audit-logs', [], $query);
            } catch (\Exception $e) {
                Log::warning('PullSecurityActivityLogs: failed to fetch logs', [
                    'site_id' => $this->site->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            if (! $response->successful()) {
                Log::warning('PullSecurityActivityLogs: API error', [
                    'site_id' => $this->site->id,
                    'status' => $response->status(),
                ]);

                return;
            }

            $wpLogs = $response->json()['logs'] ?? [];

            if (empty($wpLogs)) {
                break;
            }

            $mappedLogs = array_map($this->mapLog(...), $wpLogs);

            $totalIngested += $service->ingestLogs($this->site, $mappedLogs);

            $newSince = $service->latestCursor($mappedLogs);

            // Stop when the cursor cannot advance: either we are fully caught up,
            // or the connector returned a newest-first page whose max we already
            // hold. This bounds the loop against every connector version.
            if ($newSince === null || ($since !== null && $newSince <= $since)) {
                break;
            }

            $since = $newSince;
            $page++;
        } while (count($wpLogs) >= self::PAGE_LIMIT && $page < self::MAX_PAGES);

        if ($totalIngested > 0) {
            Log::info('PullSecurityActivityLogs: ingested logs', [
                'site_id' => $this->site->id,
                'count' => $totalIngested,
            ]);
        }
    }

    /**
     * Map a raw WordPress audit row onto the SecurityActivityLog shape. All values
     * remain untrusted here — validation/clamping happens in SecurityActivityService.
     *
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    private function mapLog(array $log): array
    {
        return [
            'event_type' => $log['action'] ?? $log['event_type'] ?? 'unknown',
            'username' => $log['user_login'] ?? $log['username'] ?? null,
            'object_type' => $log['object_type'] ?? null,
            'object_name' => $log['object_name'] ?? $log['description'] ?? null,
            'action' => $log['action'] ?? null,
            'ip_address' => $log['user_ip'] ?? $log['ip_address'] ?? null,
            'user_agent' => $log['user_agent'] ?? null,
            'details' => $log['details'] ?? null,
            'occurred_at' => $log['created_at'] ?? $log['occurred_at'] ?? null,
        ];
    }
}
