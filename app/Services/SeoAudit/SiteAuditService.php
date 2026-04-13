<?php
declare(strict_types=1);
namespace App\Services\SeoAudit;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\SeoMonitor;
use App\Models\Site;

class SiteAuditService
{
    public function startAudit(Site $site, string $trigger = 'manual'): SeoAudit
    {
        $monitor = $site->seoMonitor;
        if (!$monitor) { $monitor = SeoMonitor::create(['site_id' => $site->id, 'is_active' => false, 'interval_minutes' => 10080]); }
        return SeoAudit::create(['site_id' => $site->id, 'status' => SeoAuditStatus::Pending, 'data' => ['trigger' => $trigger]]);
    }
    public function getAuditProgress(SeoAudit $audit): array
    {
        $max = $audit->site->seoMonitor?->max_pages ?? (int) config('seo.crawler.default_max_pages');
        return ['status' => $audit->status->value, 'status_label' => $audit->status->label(), 'pages_crawled' => $audit->pages_crawled, 'max_pages' => $max, 'progress_percent' => $max > 0 ? min(100, (int) round(($audit->pages_crawled / $max) * 100)) : 0];
    }
    public function hasRunningAudit(Site $site): bool { return $site->seoAudits()->running()->exists(); }
}
