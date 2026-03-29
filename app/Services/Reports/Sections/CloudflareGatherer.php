<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteCloudflare;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class CloudflareGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'cloudflare';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $cf = SiteCloudflare::where('site_id', $site->id)->first();

        if (! $cf || ! $cf->is_active) {
            return [];
        }

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

        $requests = $cur?->cloudflare_requests;
        $bandwidth = $cur?->cloudflare_bandwidth_bytes;
        $cacheRatio = $cur?->cloudflare_cache_hit_ratio;

        return [
            'zone_name' => $cf->zone_name,
            'plan_type' => $cf->plan_label,
            'ssl_mode' => $cf->ssl_mode ? strtoupper($cf->ssl_mode) : '—',
            'cache_level' => $cf->cache_level ? ucfirst($cf->cache_level) : '—',
            'status' => $cf->status,
            'total_requests' => $requests,
            'total_requests_formatted' => $requests !== null ? $this->formatNumber($requests, 0, $language) : __('report.not_available', [], $language),
            'bandwidth' => $bandwidth,
            'bandwidth_formatted' => $bandwidth !== null ? $this->formatBytes((int) $bandwidth) : __('report.not_available', [], $language),
            'cache_hit_ratio' => $cacheRatio,
            'cache_hit_ratio_formatted' => $cacheRatio !== null ? $this->formatNumber($cacheRatio, 1, $language).'%' : __('report.not_available', [], $language),
            'requests_trend' => $this->calculateTrend($requests, $prev?->cloudflare_requests),
            'bandwidth_trend' => $this->calculateTrend($bandwidth, $prev?->cloudflare_bandwidth_bytes),
            'cache_ratio_trend' => $this->calculateTrend($cacheRatio, $prev?->cloudflare_cache_hit_ratio),
        ];
    }
}
