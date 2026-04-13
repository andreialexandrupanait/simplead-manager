<?php
declare(strict_types=1);
namespace App\Jobs;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\SeoAudit\AuditDiffService;
use App\Services\SeoAudit\ScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateSeoScores implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2; public int $timeout = 120;
    public function __construct(public Site $site, public SeoAudit $audit) { $this->onQueue('performance'); }
    public function handle(ScoringService $ss, AuditDiffService $ds): void
    {
        $tid = 'seo-audit-'.$this->site->id;
        $this->audit->markAs(SeoAuditStatus::Scoring); JobTracker::progress($tid, 92, 'Calculating scores...');
        $scores = $ss->calculateScores($this->audit);
        $this->audit->update(['score' => $scores['overall'], 'category_scores' => $scores['categories'], 'scan_duration' => $this->audit->created_at ? (int) now()->diffInSeconds($this->audit->created_at) : null]);
        $prev = SeoAudit::where('site_id', $this->site->id)->where('id', '!=', $this->audit->id)->completed()->latest('scanned_at')->first();
        if ($prev) { $this->audit->update(['data' => array_merge($this->audit->data ?? [], ['diff' => $ds->diff($this->audit, $prev)])]); }
        $m = $this->site->seoMonitor;
        if ($m) {
            $next = now()->addMinutes($m->interval_minutes);
            $preferredTime = $m->audit_config['preferred_time'] ?? null;
            if ($preferredTime) {
                [$h, $i] = explode(':', $preferredTime);
                $next->setTime((int) $h, (int) $i);
                if ($next->isPast()) {
                    $next->addDay();
                }
            }
            $m->update(['last_audit_at' => now(), 'next_audit_at' => $next]);
        }
        $this->audit->markAs(SeoAuditStatus::Completed);
        CircuitBreakerService::recordSuccess($this->site);
        $ti = $this->audit->totalIssues(); $sev = $this->audit->critical_count > 0 ? 'critical' : ($this->audit->high_count > 0 ? 'warning' : 'info');
        ActivityLogger::log('seo', $sev, "SEO audit — Score: {$scores['overall']}/100", "{$ti} issues ({$this->audit->pages_crawled} pages)", $this->site);
        JobTracker::complete($tid, "SEO audit complete — Score: {$scores['overall']}/100");
    }
    public function failed(?\Throwable $e): void { $this->audit->markAs(SeoAuditStatus::Failed, $e?->getMessage()); CircuitBreakerService::recordFailure($this->site, $e?->getMessage() ?? 'Scoring failed'); JobTracker::fail('seo-audit-'.$this->site->id, 'Scoring failed'); }
}
