<?php
declare(strict_types=1);
namespace App\Dispatchers;

use App\Enums\SeoAuditStatus;
use App\Jobs\RunSeoAudit;
use App\Models\SeoAudit;
use App\Models\SeoMonitor;

class SeoAuditDispatcher
{
    public function __invoke(): void
    {
        SeoMonitor::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_audit_at')->orWhere('next_audit_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->whereDoesntHave('audits', fn ($q) => $q->whereIn('status', [SeoAuditStatus::Pending, SeoAuditStatus::Crawling, SeoAuditStatus::Analyzing, SeoAuditStatus::Scoring]))
            ->with('site')
            ->each(function (SeoMonitor $monitor) {
                $audit = SeoAudit::create(['site_id' => $monitor->site_id, 'status' => SeoAuditStatus::Pending, 'data' => ['trigger' => 'scheduled']]);
                RunSeoAudit::dispatch($monitor->site, $audit);
            });
    }
}
