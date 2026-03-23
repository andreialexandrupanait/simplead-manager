<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudflareConnection;
use App\Models\SiteCloudflare;
use App\Services\CloudflareService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCloudflareZone implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

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

        try {
            $zone = $service->getZoneDetails($this->siteCloudflare->zone_id);

            if (empty($zone)) {
                return;
            }

            $this->siteCloudflare->update([
                'status' => $zone['status'] ?? $this->siteCloudflare->status,
                'is_paused' => $zone['paused'] ?? $this->siteCloudflare->is_paused,
                'plan_type' => $zone['plan']['legacy_id'] ?? $zone['plan']['name'] ?? $this->siteCloudflare->plan_type,
            ]);

            // Fetch SSL mode
            try {
                $sslMode = $service->getSslMode($this->siteCloudflare->zone_id);
                $this->siteCloudflare->update(['ssl_mode' => $sslMode]);
            } catch (\Exception $e) {
                // Non-critical
            }
        } catch (\Exception $e) {
            if ($this->attempts() >= $this->tries) {
                throw $e;
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error("Cloudflare zone sync failed for SiteCloudflare {$this->siteCloudflare->id}", [
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'code' => $exception?->getCode(),
        ]);
    }
}
