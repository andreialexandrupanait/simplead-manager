<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Backlink;
use App\Models\PerformanceTest;
use App\Models\SeoAlertRule;
use App\Models\SeoAudit;
use App\Models\SeoContent;
use App\Models\SeoIssue;
use App\Models\Site;
use App\Models\SiteCrawl;
use App\Models\TrackedKeyword;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SeoDashboardService
{
    public function getKpis(): array
    {
        return Cache::remember('seo-dashboard-kpis', 60, function () {
            $siteIds = $this->scopedSiteIds();

            $monitored = Site::whereIn('id', $siteIds)
                ->whereHas('seoMonitor', fn ($q) => $q->where('is_active', true))
                ->count();

            $activeCrawls = SiteCrawl::whereIn('site_id', $siteIds)
                ->where('status', SiteCrawl::STATUS_RUNNING)
                ->count();

            $criticalIssues = SeoIssue::whereIn('site_id', $siteIds)
                ->whereNull('resolved_at')
                ->where('severity', 'critical')
                ->count();

            $articlesThisMonth = SeoContent::whereIn('site_id', $siteIds)
                ->where('status', 'published')
                ->where('published_at', '>=', now()->startOfMonth())
                ->count();

            $keywordsTracked = TrackedKeyword::whereIn('site_id', $siteIds)->count();

            $avgScore = SeoAudit::whereIn('site_id', $siteIds)
                ->whereIn('id', function ($q) {
                    $q->select(DB::raw('MAX(id)'))
                        ->from('seo_audits')
                        ->groupBy('site_id');
                })
                ->avg('score');

            $totalBacklinks = Backlink::whereIn('site_id', $siteIds)
                ->active()
                ->count();

            $alertsTriggeredThisWeek = SeoAlertRule::whereIn('site_id', $siteIds)
                ->whereNotNull('last_triggered_at')
                ->where('last_triggered_at', '>=', now()->startOfWeek())
                ->count();

            $avgCwvScore = PerformanceTest::whereIn('site_id', $siteIds)
                ->where('status', 'completed')
                ->where('device', 'mobile')
                ->whereIn('id', function ($q) {
                    $q->select(DB::raw('MAX(id)'))
                        ->from('performance_tests')
                        ->where('device', 'mobile')
                        ->where('status', 'completed')
                        ->groupBy('site_id');
                })
                ->avg('performance_score');

            return [
                'monitored' => $monitored,
                'active_crawls' => $activeCrawls,
                'critical_issues' => $criticalIssues,
                'articles_this_month' => $articlesThisMonth,
                'keywords_tracked' => $keywordsTracked,
                'avg_score' => $avgScore !== null ? round((float) $avgScore, 1) : null,
                'total_backlinks' => $totalBacklinks,
                'alerts_triggered_week' => $alertsTriggeredThisWeek,
                'avg_cwv_score' => $avgCwvScore !== null ? round((float) $avgCwvScore) : null,
            ];
        });
    }

    public function getActivityFeed(int $limit = 15): Collection
    {
        $siteIds = $this->scopedSiteIds();

        $crawls = SiteCrawl::with('site')
            ->whereIn('site_id', $siteIds)
            ->whereIn('status', [SiteCrawl::STATUS_COMPLETED, SiteCrawl::STATUS_FAILED])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SiteCrawl $c) => [
                'type' => 'crawl',
                'site' => $c->site?->name ?? '—',
                'site_slug' => $c->site?->slug,
                'status' => $c->status,
                'detail' => $c->pages_crawled.' pages, '.$c->errors_count.' errors',
                'date' => $c->completed_at ?? $c->created_at,
            ]);

        $audits = SeoAudit::with('site')
            ->whereIn('site_id', $siteIds)
            ->latest('scanned_at')
            ->limit($limit)
            ->get()
            ->map(fn (SeoAudit $a) => [
                'type' => 'audit',
                'site' => $a->site?->name ?? '—',
                'site_slug' => $a->site?->slug,
                'status' => 'completed',
                'detail' => 'Score: '.$a->score.'/100',
                'date' => $a->scanned_at,
            ]);

        return $crawls->concat($audits)
            ->sortByDesc('date')
            ->take($limit)
            ->values();
    }

    /**
     * @return int[]
     */
    private function scopedSiteIds(): array
    {
        $query = Site::query();

        if (! auth()->user()?->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query->pluck('id')->all();
    }
}
