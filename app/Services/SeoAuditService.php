<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeoIssueSeverity;
use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use App\Services\SeoChecks\BacklinksCheck;
use App\Services\SeoChecks\BrokenLinksCheck;
use App\Services\SeoChecks\ContentAnalysisCheck;
use App\Services\SeoChecks\CoreWebVitalsCheck;
use App\Services\SeoChecks\DuplicateContentCheck;
use App\Services\SeoChecks\IndexCoverageCheck;
use App\Services\SeoChecks\KeywordCannibalizationCheck;
use App\Services\SeoChecks\ZeroTrafficCheck;
use App\Services\SeoChecks\MetaTagsCheck;
use App\Services\SeoChecks\OnPageScoreCheck;
use App\Services\SeoChecks\RedirectChainCheck;
use App\Services\SeoChecks\RobotsTxtCheck;
use App\Services\SeoChecks\SeoPluginCheck;
use App\Services\SeoChecks\SitemapCheck;
use App\Services\SeoChecks\StructuredDataCheck;
use Illuminate\Support\Facades\Log;

class SeoAuditService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function audit(Site $site, ?string $trackerKey = null): SeoAudit
    {
        $startTime = microtime(true);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 5, 'Fetching SEO data from connector...');
        }

        $connectorData = $this->fetchConnectorData($site);
        $connectorFailed = empty($connectorData) || ! isset($connectorData['seo_plugin']);

        if ($connectorFailed) {
            Log::warning("SEO audit: connector returned empty/incomplete data for site {$site->id}. Audit will have limited data.");
        }

        if ($trackerKey) {
            $msg = $connectorFailed
                ? 'Connector data unavailable — fetching Google Search Console data...'
                : 'Fetching Google Search Console data...';
            JobTracker::progress($trackerKey, 20, $msg);
        }

        $gscData = $this->fetchGscData($site);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 35, 'Running SEO checks...');
        }

        $issues = $this->runChecks($site, $connectorData, $gscData, $trackerKey);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 80, 'Calculating SEO score...');
        }

        $score = $this->calculateScore($issues);
        $counts = $this->countBySeverity($issues);
        $pageCount = $this->countPages($connectorData);
        $seoPlugin = $connectorData['seo_plugin'] ?? null;

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 88, 'Saving audit results...');
        }

        $audit = SeoAudit::create([
            'site_id' => $site->id,
            'score' => $score,
            'critical_count' => $counts['critical'],
            'high_count' => $counts['high'],
            'medium_count' => $counts['medium'],
            'low_count' => $counts['low'],
            'info_count' => $counts['info'],
            'scan_duration' => (int) (microtime(true) - $startTime),
            'pages_crawled' => $pageCount,
            'seo_plugin' => $seoPlugin ? ($seoPlugin['name'] ?? null) : null,
            'seo_plugin_version' => $seoPlugin ? ($seoPlugin['version'] ?? null) : null,
            'data' => array_merge(
                $connectorData,
                $gscData ? ['gsc_sitemaps' => $gscData['sitemaps'] ?? null, 'index_coverage' => $gscData['index_coverage'] ?? null] : [],
                $connectorFailed ? ['_connector_failed' => true] : [],
            ),
            'scanned_at' => now(),
        ]);

        $this->persistIssues($site, $audit, $issues);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 95, 'Finalizing...');
        }

        if ($score < 50) {
            NotificationService::notifySiteEvent(
                $site,
                'seo_score_critical',
                'SEO Score Critical',
                "SEO audit score for {$site->name} is {$score}/100. Immediate attention required.",
                [
                    'Score' => "{$score}/100",
                    'Critical Issues' => $counts['critical'],
                    'High Issues' => $counts['high'],
                ],
                'critical'
            );
        }

        ActivityLogger::log(
            'seo',
            $score < 50 ? 'critical' : ($score < 80 ? 'warning' : 'info'),
            "SEO audit completed — Score: {$score}/100",
            "Found {$counts['critical']} critical, {$counts['high']} high, {$counts['medium']} medium, {$counts['low']} low issues.",
            $site,
            ['score' => $score, 'issues' => $counts['critical'] + $counts['high'] + $counts['medium'] + $counts['low']],
            'magnifying-glass'
        );

        return $audit;
    }

    private function fetchConnectorData(Site $site): array
    {
        try {
            $api = $this->apiFactory->make($site);

            return $api->getSeoAnalysis();
        } catch (\Exception $e) {
            Log::warning("SEO audit: connector request failed for site {$site->id} — {$e->getMessage()}");

            return [];
        }
    }

    private function fetchGscData(Site $site): ?array
    {
        $connection = $site->searchConsoleConnection;

        if (! $connection || ! $connection->is_active) {
            return null;
        }

        $google = $connection->googleConnection;

        if (! $google || ! $google->is_active) {
            return null;
        }

        try {
            $service = new GoogleSearchConsoleService($google);
            $siteUrl = $connection->property_url;

            $gscData = [];

            try {
                $gscData['sitemaps'] = $service->getSitemaps($siteUrl);
            } catch (\Exception $e) {
                Log::warning("SEO audit: GSC sitemaps failed for site {$site->id}: {$e->getMessage()}");
            }

            try {
                $homepage = $site->url;
                $inspection = $service->inspectUrl($siteUrl, $homepage);
                $indexStatus = $inspection['index_status'] ?? [];

                $gscData['index_coverage'] = [[
                    'url' => $homepage,
                    'verdict' => $indexStatus['verdict'] ?? 'VERDICT_UNSPECIFIED',
                    'coverage_state' => $indexStatus['coverage_state'] ?? '',
                ]];
            } catch (\Exception $e) {
                Log::warning("SEO audit: GSC URL inspection failed for site {$site->id}: {$e->getMessage()}");
            }

            return $gscData ?: null;

        } catch (\Exception $e) {
            Log::warning("SEO audit: GSC data fetch failed for site {$site->id}: {$e->getMessage()}");

            return null;
        }
    }

    private function runChecks(Site $site, array $connectorData, ?array $gscData, ?string $trackerKey): array
    {
        $checks = [
            new SeoPluginCheck,
            new MetaTagsCheck,
            new RobotsTxtCheck,
            new SitemapCheck,
            new StructuredDataCheck,
            new RedirectChainCheck,
            new BrokenLinksCheck,
            new OnPageScoreCheck,
            new IndexCoverageCheck,
            new ContentAnalysisCheck,
            (new CoreWebVitalsCheck)->withSiteId($site->id),
            new DuplicateContentCheck,
            (new BacklinksCheck)->withSiteId($site->id),
            (new KeywordCannibalizationCheck)->withSiteId($site->id),
            (new ZeroTrafficCheck)->withSiteId($site->id),
        ];

        $issues = [];
        $total = count($checks);
        $done = 0;

        foreach ($checks as $check) {
            $checkIssues = $check->check($connectorData, $gscData);
            $issues = array_merge($issues, $checkIssues);

            $done++;

            if ($trackerKey) {
                $progress = 35 + (int) round(($done / $total) * 45);
                $checkName = class_basename($check);
                JobTracker::progress($trackerKey, $progress, "Running {$checkName}...");
            }
        }

        return $issues;
    }

    private function calculateScore(array $issues): int
    {
        $score = 100;
        $penaltiesBySeverity = [];

        foreach ($issues as $issue) {
            $severityValue = $issue['severity'] ?? 'info';
            $severity = SeoIssueSeverity::tryFrom($severityValue);

            if (! $severity || $severity === SeoIssueSeverity::Info) {
                continue;
            }

            $penaltiesBySeverity[$severityValue] = ($penaltiesBySeverity[$severityValue] ?? 0) + $severity->penalty();
        }

        foreach ($penaltiesBySeverity as $severityValue => $totalPenalty) {
            $severity = SeoIssueSeverity::tryFrom($severityValue);
            if (! $severity) {
                continue;
            }

            $capped = min($totalPenalty, $severity->maxPenalty());
            $score -= $capped;
        }

        return max(0, $score);
    }

    private function countBySeverity(array $issues): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];

        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? 'info';
            if (array_key_exists($severity, $counts)) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    private function countPages(array $connectorData): int
    {
        $count = 0;

        if (! empty($connectorData['homepage'])) {
            $count++;
        }

        $count += count($connectorData['pages'] ?? []);

        return $count;
    }

    private function persistIssues(Site $site, SeoAudit $audit, array $issues): void
    {
        SeoIssue::where('site_id', $site->id)->delete();

        $records = [];
        $now = now();

        foreach ($issues as $issue) {
            $title = $issue['title'] ?? 'Unknown issue';
            $description = $issue['description'] ?? null;
            $recommendation = $issue['recommendation'] ?? null;
            $url = $issue['url'] ?? null;

            // Ensure all text fields are strings (some checks may return arrays)
            if (is_array($title)) {
                $title = implode(', ', $title);
            }
            if (is_array($description)) {
                $description = implode(' ', $description);
            }
            if (is_array($recommendation)) {
                $recommendation = implode(' ', $recommendation);
            }
            if (is_array($url)) {
                $url = $url[0] ?? null;
            }

            $records[] = [
                'site_id' => $site->id,
                'seo_audit_id' => $audit->id,
                'category' => (string) ($issue['category'] ?? 'technical'),
                'severity' => (string) ($issue['severity'] ?? 'info'),
                'title' => mb_substr((string) $title, 0, 500),
                'description' => $description !== null ? mb_substr((string) $description, 0, 2000) : null,
                'url' => $url !== null ? mb_substr((string) $url, 0, 2048) : null,
                'recommendation' => $recommendation !== null ? mb_substr((string) $recommendation, 0, 2000) : null,
                'meta' => isset($issue['meta']) ? json_encode($issue['meta']) : null,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($records)) {
            SeoIssue::insert($records);
        }
    }
}
