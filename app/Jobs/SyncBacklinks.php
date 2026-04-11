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

    public int $timeout = 600;

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
        $result = app(BacklinkService::class)->fullSync($this->site);

        ActivityLogger::log(
            type: 'seo',
            severity: 'info',
            title: "Backlinks synced for {$this->site->name}",
            description: "GSC: {$result['gsc_synced']}, Crawled: {$result['crawled']}, Verified: {$result['verified']}, Lost: {$result['lost']}",
            site: $this->site,
            metadata: $result,
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
