<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Enums\SeoAuditStatus;
use App\Jobs\RunSeoAudit;
use App\Models\SeoAudit;
use App\Models\SeoMonitor;
use App\Services\CircuitBreakerService;
use Illuminate\Support\Facades\Log;

class SeoAuditDispatcher
{
    public function __invoke(): void
    {
        CircuitBreakerService::checkHalfOpen();
        $this->cleanupStaleAudits();

        SeoMonitor::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_audit_at')->orWhere('next_audit_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->where(fn ($q) => $q
                ->whereDoesntHave('site.healthState')
                ->orWhereHas('site.healthState', fn ($q2) => $q2
                    ->where('circuit_state', '!=', 'open')
                    ->where('is_monitoring_disabled', false)
                )
            )
            ->whereDoesntHave('audits', fn ($q) => $q->whereIn('status', [
                SeoAuditStatus::Pending, SeoAuditStatus::Crawling,
                SeoAuditStatus::Analyzing, SeoAuditStatus::Scoring,
            ]))
            ->with('site')
            ->each(function (SeoMonitor $monitor) {
                $audit = SeoAudit::create([
                    'site_id' => $monitor->site_id,
                    'status' => SeoAuditStatus::Pending,
                    'data' => ['trigger' => 'scheduled'],
                ]);
                RunSeoAudit::dispatch($monitor->site, $audit);
            });
    }

    private function cleanupStaleAudits(): void
    {
        SeoAudit::running()
            ->where('updated_at', '<', now()->subMinutes(30))
            ->each(function (SeoAudit $audit) {
                $audit->markAs(SeoAuditStatus::Failed, 'Audit timed out — no progress for 30+ minutes');
                Log::warning('SEO: stale audit marked failed', ['audit_id' => $audit->id, 'site_id' => $audit->site_id]);
            });
    }
}
