<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\BacklinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncBacklinks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'sync-backlinks-'.$this->site->id;
    }

    public function handle(): void
    {
        $service = app(BacklinkService::class);

        // Primary: discover from crawl data (works without GSC)
        $crawlDiscovered = $service->discoverFromCrawl($this->site);

        // Supplementary: GSC data if available (gracefully returns 0 if not connected)
        $gscSynced = $service->syncFromGsc($this->site);

        $lost = $service->detectLostBacklinks($this->site);
        $snapshot = $service->createSnapshot($this->site);

        $total = $crawlDiscovered + $gscSynced;

        ActivityLogger::log(
            type: 'seo',
            severity: 'info',
            title: "Backlinks synced for {$this->site->name}",
            description: "Discovered {$crawlDiscovered} from crawl, {$gscSynced} from GSC, {$lost} lost. Total: {$snapshot->total_backlinks}, Domains: {$snapshot->referring_domains}",
            site: $this->site,
            metadata: ['crawl_discovered' => $crawlDiscovered, 'gsc_synced' => $gscSynced, 'lost' => $lost, 'total' => $snapshot->total_backlinks, 'referring_domains' => $snapshot->referring_domains],
            icon: 'link',
        );
    }

    public function failed(?\Throwable $exception): void
    {
        ActivityLogger::log(
            type: 'seo',
            severity: 'warning',
            title: "Backlink sync failed for {$this->site->name}",
            description: $exception?->getMessage() ?? 'Unknown error',
            site: $this->site,
            metadata: ['error' => $exception?->getMessage()],
            icon: 'arrow-top-right-on-square',
        );
    }
}
