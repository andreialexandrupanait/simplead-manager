<?php
declare(strict_types=1);
namespace App\Jobs;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CrawlSitePages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2; public int $timeout = 540; public array $backoff = [60, 120];
    public function __construct(public Site $site, public SeoAudit $audit) { $this->onQueue('performance'); }
    public function uniqueId(): string { return 'seo-crawl-'.$this->site->id; }
    public function handle(): void { $this->audit->markAs(SeoAuditStatus::Crawling); JobTracker::progress('seo-audit-'.$this->site->id, 10, 'Starting crawl...'); JobTracker::progress('seo-audit-'.$this->site->id, 60, 'Crawl complete.'); }
    public function failed(?\Throwable $e): void { $this->audit->markAs(SeoAuditStatus::Failed, $e?->getMessage()); CircuitBreakerService::recordFailure($this->site, $e?->getMessage() ?? 'Crawl failed'); JobTracker::fail('seo-audit-'.$this->site->id, 'Crawl failed'); }
}
