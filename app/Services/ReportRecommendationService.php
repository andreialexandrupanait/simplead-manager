<?php

namespace App\Services;

use App\Models\ReportRecommendation;
use App\Models\Site;

class ReportRecommendationService
{
    public function __construct(
        protected array $data,
        protected string $language = 'ro'
    ) {}

    /**
     * Generate recommendations and persist them as draft rows for a site.
     * Clears existing auto-generated drafts first.
     */
    public function generateAndPersist(Site $site): void
    {
        // Delete existing auto-generated drafts (not yet linked to a report)
        ReportRecommendation::where('site_id', $site->id)
            ->whereNull('report_id')
            ->where('is_auto_generated', true)
            ->delete();

        $allRecs = $this->generate();
        $sortOrder = 0;

        foreach ($allRecs as $category => $recs) {
            foreach ($recs as $rec) {
                ReportRecommendation::create([
                    'site_id' => $site->id,
                    'category' => $category,
                    'priority' => $rec['priority'] ?? 'medium',
                    'title' => $rec['title'],
                    'description' => $rec['description'],
                    'is_auto_generated' => true,
                    'is_included' => true,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
    }

    /**
     * Generate rule-based recommendations grouped by category.
     *
     * @return array ['technical' => [...], 'performance' => [...], 'seo' => [...]]
     */
    public function generate(): array
    {
        return [
            'technical' => $this->getTechnicalRecs(3),
            'performance' => $this->getPerformanceRecs(2),
            'seo' => $this->getSeoRecs(2),
        ];
    }

    protected function getTechnicalRecs(int $max): array
    {
        $recs = [];

        $uptime = $this->data['uptime'] ?? [];
        $backups = $this->data['backups'] ?? [];
        $security = $this->data['security'] ?? [];
        $updates = $this->data['updates'] ?? [];

        $uptimePct = $uptime['uptime_percentage'] ?? null;
        $avgResponse = $uptime['avg_response_time'] ?? null;
        $incidentsCount = $uptime['incidents_count'] ?? 0;
        $backupsFailed = $backups['failed_count'] ?? 0;
        $backupsEnabled = $backups['schedule_enabled'] ?? true;
        $securityScore = $security['score'] ?? null;
        $criticalCount = $security['critical_count'] ?? 0;
        $updatesFailed = $updates['failed_count'] ?? 0;

        // Rule 1: Uptime < 99.5%
        if ($uptimePct !== null && $uptimePct < 99.5) {
            $recs[] = ['title' => __('report.rec_hosting_reliability', [], $this->language), 'description' => __('report.rec_hosting_reliability_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 2: Response time > 500ms
        if ($avgResponse !== null && $avgResponse > 500) {
            $recs[] = ['title' => __('report.rec_server_caching', [], $this->language), 'description' => __('report.rec_server_caching_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 3: Response time > 300ms (but <=500)
        if ($avgResponse !== null && $avgResponse > 300 && $avgResponse <= 500) {
            $recs[] = ['title' => __('report.rec_response_optimization', [], $this->language), 'description' => __('report.rec_response_optimization_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 4: More than 3 incidents
        if ($incidentsCount > 3) {
            $recs[] = ['title' => __('report.rec_downtime_investigation', [], $this->language), 'description' => __('report.rec_downtime_investigation_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 5: Failed backups
        if ($backupsFailed > 0) {
            $recs[] = ['title' => __('report.rec_backup_reliability', [], $this->language), 'description' => __('report.rec_backup_reliability_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 6: Backups not enabled
        if (! $backupsEnabled) {
            $recs[] = ['title' => __('report.rec_enable_backups', [], $this->language), 'description' => __('report.rec_enable_backups_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 7: Security score < 70
        if ($securityScore !== null && $securityScore < 70) {
            $recs[] = ['title' => __('report.rec_security_hardening', [], $this->language), 'description' => __('report.rec_security_hardening_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 8: Security score 70-89
        if ($securityScore !== null && $securityScore >= 70 && $securityScore < 90) {
            $recs[] = ['title' => __('report.rec_security_best_practices', [], $this->language), 'description' => __('report.rec_security_best_practices_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 9: Critical vulnerabilities
        if ($criticalCount > 0) {
            $recs[] = ['title' => __('report.rec_critical_vulnerabilities', [], $this->language), 'description' => __('report.rec_critical_vulnerabilities_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 10: Update failures
        if ($updatesFailed > 0) {
            $recs[] = ['title' => __('report.rec_update_failures', [], $this->language), 'description' => __('report.rec_update_failures_desc', [], $this->language), 'priority' => 'medium'];
        }

        return $this->prioritySortAndFill($recs, $max, 'technical');
    }

    protected function getPerformanceRecs(int $max): array
    {
        $recs = [];

        $perf = $this->data['performance'] ?? [];
        $mobileScore = $perf['mobile_score'] ?? null;
        $desktopScore = $perf['desktop_score'] ?? null;
        $mobile = $perf['mobile'] ?? [];

        // Rule 1: Mobile < 50
        if ($mobileScore !== null && $mobileScore < 50) {
            $recs[] = ['title' => __('report.rec_critical_mobile', [], $this->language), 'description' => __('report.rec_critical_mobile_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 2: Mobile 50-79
        if ($mobileScore !== null && $mobileScore >= 50 && $mobileScore < 80) {
            $recs[] = ['title' => __('report.rec_mobile_optimization', [], $this->language), 'description' => __('report.rec_mobile_optimization_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 3: Desktop < 50
        if ($desktopScore !== null && $desktopScore < 50) {
            $recs[] = ['title' => __('report.rec_desktop_critical', [], $this->language), 'description' => __('report.rec_desktop_critical_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 4: Desktop 50-79
        if ($desktopScore !== null && $desktopScore >= 50 && $desktopScore < 80) {
            $recs[] = ['title' => __('report.rec_desktop_optimization', [], $this->language), 'description' => __('report.rec_desktop_optimization_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 5: LCP red
        if (($mobile['lcp_color'] ?? '') === 'red') {
            $recs[] = ['title' => __('report.rec_lcp_improvement', [], $this->language), 'description' => __('report.rec_lcp_improvement_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 6: CLS red
        if (($mobile['cls_color'] ?? '') === 'red') {
            $recs[] = ['title' => __('report.rec_layout_stability', [], $this->language), 'description' => __('report.rec_layout_stability_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 7: TBT red
        if (($mobile['tbt_color'] ?? '') === 'red') {
            $recs[] = ['title' => __('report.rec_js_blocking', [], $this->language), 'description' => __('report.rec_js_blocking_desc', [], $this->language), 'priority' => 'high'];
        }

        return $this->prioritySortAndFill($recs, $max, 'performance');
    }

    protected function getSeoRecs(int $max): array
    {
        $recs = [];

        $analytics = $this->data['analytics'] ?? [];
        $sc = $this->data['search_console'] ?? [];
        $overview = $sc['overview'] ?? [];

        $bounceRate = $analytics['bounce_rate'] ?? null;
        $avgPosition = $overview['avg_position'] ?? null;
        $avgCtr = $overview['avg_ctr'] ?? null;
        $avgDuration = $analytics['avg_session_duration'] ?? null;

        // Rule 1: Bounce rate > 70%
        if ($bounceRate !== null && $bounceRate > 70) {
            $recs[] = ['title' => __('report.rec_high_bounce', [], $this->language), 'description' => __('report.rec_high_bounce_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 2: Bounce rate 50-70%
        if ($bounceRate !== null && $bounceRate > 50 && $bounceRate <= 70) {
            $recs[] = ['title' => __('report.rec_moderate_bounce', [], $this->language), 'description' => __('report.rec_moderate_bounce_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 3: Avg position > 20
        if ($avgPosition !== null && $avgPosition > 20) {
            $recs[] = ['title' => __('report.rec_low_visibility', [], $this->language), 'description' => __('report.rec_low_visibility_desc', [], $this->language), 'priority' => 'high'];
        }

        // Rule 4: Avg position 10-20
        if ($avgPosition !== null && $avgPosition > 10 && $avgPosition <= 20) {
            $recs[] = ['title' => __('report.rec_improve_rankings', [], $this->language), 'description' => __('report.rec_improve_rankings_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 5: Low CTR
        if ($avgCtr !== null && $avgCtr < 0.02) {
            $recs[] = ['title' => __('report.rec_low_ctr', [], $this->language), 'description' => __('report.rec_low_ctr_desc', [], $this->language), 'priority' => 'medium'];
        }

        // Rule 6: Low engagement
        if ($avgDuration !== null && $avgDuration < 60) {
            $recs[] = ['title' => __('report.rec_low_engagement', [], $this->language), 'description' => __('report.rec_low_engagement_desc', [], $this->language), 'priority' => 'medium'];
        }

        return $this->prioritySortAndFill($recs, $max, 'seo');
    }

    /**
     * Sort by priority (high first), take top $max. No fallback filler recs.
     */
    protected function prioritySortAndFill(array $recs, int $max, string $category): array
    {
        $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];

        usort($recs, function ($a, $b) use ($priorityOrder) {
            return ($priorityOrder[$a['priority']] ?? 2) <=> ($priorityOrder[$b['priority']] ?? 2);
        });

        return array_slice($recs, 0, $max);
    }
}
