<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\CloudflareRateLimitException;
use App\Models\CloudflareConnection;
use App\Models\SiteCloudflare;
use App\Services\CloudflareService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCloudflareZone implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public int $uniqueFor = 180; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [15, 30];

    public function __construct(
        public SiteCloudflare $siteCloudflare,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'cf-sync-'.$this->siteCloudflare->id;
    }

    public function handle(): void
    {
        /** @var CloudflareConnection|null $connection */
        $connection = $this->siteCloudflare->cloudflareConnection;

        if (! $connection || ! $connection->is_valid) {
            return;
        }

        $service = new CloudflareService($connection);

        // P2-65: a rate-limit hit is transient, not a failure. DEFER — release
        // the job back to the queue to retry after the window frees up — rather
        // than throwing (which would burn an attempt and eventually mark the job
        // failed for a condition that resolves itself).
        try {
            $this->syncZone($service);
        } catch (CloudflareRateLimitException $e) {
            $this->release($e->retryAfter);
        }
    }

    private function syncZone(CloudflareService $service): void
    {
        // P1-57: do NOT swallow exceptions. A genuine fetch failure must surface
        // so Laravel's retry (tries/backoff) engages and failed() fires once
        // attempts are exhausted — instead of the job reporting false success on
        // the first failed attempt and leaving the data silently stale.
        $zone = $service->getZoneDetails($this->siteCloudflare->zone_id);

        if (empty($zone)) {
            return;
        }

        // P1-61: carry each field forward when Cloudflare omits it; never
        // overwrite a stored value with a fabricated default from a failed fetch.
        $updates = [
            'status' => $zone['status'] ?? $this->siteCloudflare->status,
            'is_paused' => $zone['paused'] ?? $this->siteCloudflare->is_paused,
            'plan_type' => $zone['plan']['legacy_id'] ?? $zone['plan']['name'] ?? $this->siteCloudflare->plan_type,
            'last_sync_at' => now(),
        ];

        // SSL mode is a non-critical sub-fetch. P1-61: if it fails, keep the last
        // known ssl_mode (carry-forward) rather than persisting getSslMode()'s
        // 'off' default, which would falsely report SSL as disabled in the UI and
        // client reports. The zone update above still commits.
        try {
            $updates['ssl_mode'] = $service->getSslMode($this->siteCloudflare->zone_id);
        } catch (RequestException|\RuntimeException|ConnectionException $e) {
            // Carry forward the last known ssl_mode.
        }

        $this->siteCloudflare->update($updates);
    }

    public function failed(?\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error("Cloudflare zone sync failed for SiteCloudflare {$this->siteCloudflare->id}", [
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'code' => $exception?->getCode(),
        ]);
    }
}
