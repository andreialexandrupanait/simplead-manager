<?php
declare(strict_types=1);
namespace App\Jobs;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeSeoPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2; public int $timeout = 300; public array $backoff = [60];
    public function __construct(public Site $site, public SeoAudit $audit) { $this->onQueue('performance'); }
    public function handle(): void { $this->audit->markAs(SeoAuditStatus::Analyzing); JobTracker::progress('seo-audit-'.$this->site->id, 75, 'Analyzing...'); JobTracker::progress('seo-audit-'.$this->site->id, 90, 'Analysis complete.'); }
    public function failed(?\Throwable $e): void { $this->audit->markAs(SeoAuditStatus::Failed, $e?->getMessage()); CircuitBreakerService::recordFailure($this->site, $e?->getMessage() ?? 'Analysis failed'); JobTracker::fail('seo-audit-'.$this->site->id, 'Analysis failed'); }
}
