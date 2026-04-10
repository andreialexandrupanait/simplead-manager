<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SiteCrawl;
use App\Services\Crawler\SiteCrawlerService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSiteCrawl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public SiteCrawl $crawl,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $trackerKey = 'crawl-'.$this->crawl->id;

        JobTracker::start($trackerKey, 'Initialising site crawl...');

        (new SiteCrawlerService)->crawl($this->crawl, $trackerKey);

        JobTracker::complete(
            $trackerKey,
            "Crawl complete — {$this->crawl->fresh()?->pages_crawled} pages crawled."
        );
    }

    public function failed(?\Throwable $exception): void
    {
        $this->crawl->update([
            'status' => SiteCrawl::STATUS_FAILED,
            'completed_at' => now(),
        ]);

        JobTracker::fail(
            'crawl-'.$this->crawl->id,
            'Site crawl failed: '.($exception?->getMessage() ?? 'Unknown error'),
        );
    }
}
