<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Backlink;
use App\Models\BacklinkSnapshot;
use App\Models\PerformanceTest;
use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\Site;
use App\Models\TrackedKeyword;

class SeoAgentService
{
    /**
     * Run a complete SEO analysis and generate prioritized recommendations.
     *
     * Returns a structured report with health score, sections, and action items.
     */
    public function analyze(Site $site): array
    {
        $audit = $site->latestSeoAudit;
        $issues = $audit ? SeoIssue::where('seo_audit_id', $audit->id)->whereNull('resolved_at')->orderBySeverity()->get() : collect();

        $keywordData = $this->analyzeKeywords($site);
        $technicalData = $this->analyzeTechnical($site, $audit);
        $cwvData = $this->analyzeCoreWebVitals($site);
        $backlinkData = $this->analyzeBacklinks($site);
        $contentData = $this->analyzeContent($site, $audit);

        $healthScore = $this->calculateHealthScore($audit, $keywordData, $cwvData, $backlinkData);

        $actions = $this->generateActionItems($issues, $keywordData, $technicalData, $cwvData, $backlinkData, $contentData);

        usort($actions, fn ($a, $b) => $b['impact'] <=> $a['impact']);

        return [
            'health_score' => $healthScore,
            'generated_at' => now(),
            'audit' => $audit ? [
                'score' => $audit->score,
                'issues_count' => $issues->count(),
                'critical' => $audit->critical_count,
                'high' => $audit->high_count,
                'scanned_at' => $audit->scanned_at,
                'seo_plugin' => $audit->seo_plugin,
            ] : null,
            'sections' => [
                'technical' => $technicalData,
                'keywords' => $keywordData,
                'cwv' => $cwvData,
                'backlinks' => $backlinkData,
                'content' => $contentData,
            ],
            'actions' => array_slice($actions, 0, 20),
            'summary' => $this->generateSummary($healthScore, $actions),
        ];
    }

    private function calculateHealthScore(?SeoAudit $audit, array $keywords, array $cwv, array $backlinks): int
    {
        $scores = [];

        // Audit score (40% weight)
        if ($audit) {
            $scores[] = ['value' => $audit->score, 'weight' => 40];
        }

        // CWV score (20% weight)
        if ($cwv['performance_score'] !== null) {
            $scores[] = ['value' => $cwv['performance_score'], 'weight' => 20];
        }

        // Keyword health (20% weight) — based on tracked count and avg position
        $kwScore = 50; // neutral
        if ($keywords['tracked'] > 0) {
            $kwScore = $keywords['avg_position'] !== null && $keywords['avg_position'] < 20 ? 80 : 60;
            if ($keywords['in_top_10'] > 0) {
                $kwScore = min(100, $kwScore + $keywords['in_top_10'] * 5);
            }
        }
        $scores[] = ['value' => $kwScore, 'weight' => 20];

        // Backlink health (20% weight)
        $blScore = 30; // low by default
        if ($backlinks['total'] > 0) {
            $blScore = 60;
            if ($backlinks['referring_domains'] >= 10) {
                $blScore = 75;
            }
            if ($backlinks['toxic_percent'] > 30) {
                $blScore -= 20;
            }
        }
        $scores[] = ['value' => max(0, $blScore), 'weight' => 20];

        if (empty($scores)) {
            return 0;
        }

        $totalWeight = array_sum(array_column($scores, 'weight'));
        $weightedSum = array_sum(array_map(fn ($s) => $s['value'] * $s['weight'], $scores));

        return (int) round($weightedSum / $totalWeight);
    }

    private function analyzeKeywords(Site $site): array
    {
        $keywords = TrackedKeyword::where('site_id', $site->id)->get();
        $latestPositions = app(KeywordTrackingService::class)->getKeywordsWithLatestPosition($site);

        $inTop3 = $latestPositions->filter(fn ($k) => $k->latest_position !== null && $k->latest_position <= 3)->count();
        $inTop10 = $latestPositions->filter(fn ($k) => $k->latest_position !== null && $k->latest_position <= 10)->count();
        $inTop20 = $latestPositions->filter(fn ($k) => $k->latest_position !== null && $k->latest_position <= 20)->count();
        $noPosition = $latestPositions->filter(fn ($k) => $k->latest_position === null)->count();

        $avgPosition = $latestPositions->whereNotNull('latest_position')->avg('latest_position');
        $totalClicks = (int) $latestPositions->sum('latest_clicks');
        $totalImpressions = (int) $latestPositions->sum('latest_impressions');

        $cannibalization = app(KeywordTrackingService::class)->detectCannibalization($site);

        return [
            'tracked' => $keywords->count(),
            'in_top_3' => $inTop3,
            'in_top_10' => $inTop10,
            'in_top_20' => $inTop20,
            'no_position' => $noPosition,
            'avg_position' => $avgPosition ? round($avgPosition, 1) : null,
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'cannibalized' => count($cannibalization),
            'has_gsc' => $site->searchConsoleConnection?->is_active ?? false,
        ];
    }

    private function analyzeTechnical(Site $site, ?SeoAudit $audit): array
    {
        $data = $audit?->data ?? [];

        return [
            'has_audit' => $audit !== null,
            'connector_failed' => $data['_connector_failed'] ?? false,
            'robots_ok' => ! empty($data['robots_txt']['exists']),
            'sitemap_ok' => ! empty($data['sitemaps']['found']),
            'seo_plugin' => $audit?->seo_plugin,
            'search_visible' => $data['search_visibility']['visible'] ?? true,
            'has_structured_data' => ! empty($data['structured_data']),
            'broken_links_count' => $data['broken_links']['broken_count'] ?? 0,
        ];
    }

    private function analyzeCoreWebVitals(Site $site): array
    {
        $test = PerformanceTest::where('site_id', $site->id)
            ->where('status', 'completed')
            ->where('device', 'mobile')
            ->latest('tested_at')
            ->first();

        if (! $test) {
            return [
                'has_data' => false,
                'performance_score' => null,
                'lcp' => null,
                'cls' => null,
                'inp' => null,
                'lcp_status' => null,
                'cls_status' => null,
                'inp_status' => null,
            ];
        }

        $lcp = $test->field_lcp ?? $test->lcp;
        $cls = $test->field_cls ?? $test->cls;
        $inp = $test->field_inp;

        return [
            'has_data' => true,
            'performance_score' => $test->performance_score,
            'lcp' => $lcp,
            'cls' => $cls,
            'inp' => $inp,
            'lcp_status' => $lcp !== null ? ($lcp <= 2.5 ? 'good' : ($lcp <= 4.0 ? 'needs_improvement' : 'poor')) : null,
            'cls_status' => $cls !== null ? ($cls <= 0.1 ? 'good' : ($cls <= 0.25 ? 'needs_improvement' : 'poor')) : null,
            'inp_status' => $inp !== null ? ($inp <= 200 ? 'good' : ($inp <= 500 ? 'needs_improvement' : 'poor')) : null,
        ];
    }

    private function analyzeBacklinks(Site $site): array
    {
        $snapshot = BacklinkSnapshot::where('site_id', $site->id)->latest('date')->first();
        $totalActive = Backlink::where('site_id', $site->id)->active()->count();
        $toxicCount = Backlink::where('site_id', $site->id)->active()->toxic()->count();

        return [
            'total' => $totalActive,
            'referring_domains' => $snapshot?->referring_domains ?? 0,
            'new_30d' => $snapshot?->new_backlinks ?? 0,
            'lost_30d' => $snapshot?->lost_backlinks ?? 0,
            'toxic' => $toxicCount,
            'toxic_percent' => $totalActive > 0 ? round($toxicCount / $totalActive * 100, 1) : 0,
            'has_data' => $totalActive > 0,
        ];
    }

    private function analyzeContent(Site $site, ?SeoAudit $audit): array
    {
        $contentGaps = app(ContentIntelligenceService::class)->getContentGaps($site);
        $zeroTraffic = app(ContentIntelligenceService::class)->findPagesWithoutTraffic($site);

        return [
            'content_gaps' => count($contentGaps),
            'zero_traffic_pages' => count($zeroTraffic),
            'top_gaps' => array_slice($contentGaps, 0, 5),
        ];
    }

    private function generateActionItems(
        $issues, array $keywords, array $technical, array $cwv, array $backlinks, array $content
    ): array {
        $actions = [];

        // Critical: No SEO audit data
        if (! $technical['has_audit']) {
            $actions[] = [
                'category' => 'audit',
                'severity' => 'critical',
                'impact' => 100,
                'title' => 'Ruleaza primul audit SEO',
                'description' => 'Nu exista date de audit. Apasa "Run Audit" pe tab-ul Overview pentru a obtine o analiza completa.',
                'tab' => 'Overview',
            ];
        }

        // Critical: Connector failed
        if ($technical['connector_failed']) {
            $actions[] = [
                'category' => 'technical',
                'severity' => 'critical',
                'impact' => 95,
                'title' => 'Reconecteaza WordPress connector',
                'description' => 'Ultimul audit nu a putut comunica cu site-ul. Verifica daca plugin-ul SimpleAd Connector este activ.',
                'tab' => 'Technical',
            ];
        }

        // High: No SEO plugin
        if ($technical['has_audit'] && ! $technical['seo_plugin']) {
            $actions[] = [
                'category' => 'technical',
                'severity' => 'high',
                'impact' => 90,
                'title' => 'Instaleaza un plugin SEO (Yoast/Rank Math)',
                'description' => 'Niciun plugin SEO detectat. Instaleaza Yoast SEO sau Rank Math pentru control complet asupra meta tags, sitemaps, si structured data.',
                'tab' => 'Technical',
            ];
        }

        // High: No sitemap
        if ($technical['has_audit'] && ! $technical['sitemap_ok']) {
            $actions[] = [
                'category' => 'technical',
                'severity' => 'high',
                'impact' => 85,
                'title' => 'Genereaza un sitemap XML',
                'description' => 'Nu s-a gasit sitemap.xml. Activeaza generarea de sitemap din plugin-ul SEO si trimite-l in Google Search Console.',
                'tab' => 'Technical',
            ];
        }

        // High: Not search visible
        if (! $technical['search_visible']) {
            $actions[] = [
                'category' => 'technical',
                'severity' => 'critical',
                'impact' => 100,
                'title' => 'Site-ul blocheaza indexarea!',
                'description' => 'Search engines sunt blocate de la indexare. Verifica Settings > Reading in WordPress — "Discourage search engines" trebuie sa fie debifat.',
                'tab' => 'Technical',
            ];
        }

        // Medium: CWV poor
        if ($cwv['lcp_status'] === 'poor') {
            $actions[] = [
                'category' => 'performance',
                'severity' => 'high',
                'impact' => 80,
                'title' => "LCP prea mare ({$cwv['lcp']}s) — afecteaza rankingul",
                'description' => 'Largest Contentful Paint depaseste 4 secunde. Optimizeaza imaginile hero, activeaza lazy loading, si reduce render-blocking CSS/JS.',
                'tab' => 'Performance',
            ];
        }
        if ($cwv['cls_status'] === 'poor') {
            $actions[] = [
                'category' => 'performance',
                'severity' => 'medium',
                'impact' => 65,
                'title' => 'CLS mare — layout-ul se misca la incarcare',
                'description' => 'Cumulative Layout Shift e peste 0.25. Seteaza dimensiuni fixe pe imagini/iframe-uri si evita inserarea de continut dinamic deasupra fold-ului.',
                'tab' => 'Performance',
            ];
        }

        // Medium: No keywords tracked
        if ($keywords['tracked'] === 0) {
            $actions[] = [
                'category' => 'keywords',
                'severity' => 'medium',
                'impact' => 70,
                'title' => 'Adauga keywords pentru monitorizare',
                'description' => 'Nu monitorizezi niciun keyword. Adauga cel putin 10-20 keywords relevante pe tab-ul Keywords.',
                'tab' => 'Keywords',
            ];
        }

        // Medium: No GSC
        if (! $keywords['has_gsc']) {
            $actions[] = [
                'category' => 'keywords',
                'severity' => 'medium',
                'impact' => 75,
                'title' => 'Conecteaza Google Search Console',
                'description' => 'Fara Search Console, nu poti vedea pozitiile reale in Google, clicks, sau impressions. Conecteaza-l din setari.',
                'tab' => 'Keywords',
            ];
        }

        // Medium: Keyword cannibalization
        if ($keywords['cannibalized'] > 0) {
            $actions[] = [
                'category' => 'content',
                'severity' => 'medium',
                'impact' => 60,
                'title' => "{$keywords['cannibalized']} keyword(s) canibalizate",
                'description' => 'Mai multe pagini concureaza pe acelasi keyword. Consolideaza continutul sau diferentiaza targetarea.',
                'tab' => 'Keywords',
            ];
        }

        // Medium: No backlinks
        if (! $backlinks['has_data']) {
            $actions[] = [
                'category' => 'backlinks',
                'severity' => 'medium',
                'impact' => 65,
                'title' => 'Sincronizeaza backlinks',
                'description' => 'Nu ai date despre backlinks. Apasa "Sync All" pe tab-ul Backlinks pentru a descoperi cine linkuieste catre tine.',
                'tab' => 'Backlinks',
            ];
        }

        // Medium: Toxic backlinks
        if ($backlinks['toxic'] > 0) {
            $actions[] = [
                'category' => 'backlinks',
                'severity' => $backlinks['toxic_percent'] > 30 ? 'high' : 'medium',
                'impact' => $backlinks['toxic_percent'] > 30 ? 80 : 55,
                'title' => "{$backlinks['toxic']} backlink(s) toxice detectate",
                'description' => "Backlinks cu spam score ridicat pot afecta rankingul. Verifica-le pe tab-ul Backlinks si considera un disavow in Google Search Console.",
                'tab' => 'Backlinks',
            ];
        }

        // Low: Content gaps
        if ($content['content_gaps'] > 0) {
            $actions[] = [
                'category' => 'content',
                'severity' => 'low',
                'impact' => 50,
                'title' => "{$content['content_gaps']} oportunitati de continut",
                'description' => 'Keywords cu impressions dar fara clicks — optimizeaza title tags si meta descriptions pentru a creste CTR-ul.',
                'tab' => 'Keywords',
            ];
        }

        // Low: Zero traffic pages
        if ($content['zero_traffic_pages'] > 10) {
            $actions[] = [
                'category' => 'content',
                'severity' => 'low',
                'impact' => 40,
                'title' => "{$content['zero_traffic_pages']} pagini fara trafic organic",
                'description' => 'Aceste pagini sunt indexate dar nu primesc vizite. Imbunatateste continutul sau consolideaza-le.',
                'tab' => 'Keywords',
            ];
        }

        // Add high-severity audit issues as action items
        foreach ($issues->where('severity', 'critical')->take(3) as $issue) {
            $actions[] = [
                'category' => 'audit',
                'severity' => 'critical',
                'impact' => 88,
                'title' => $issue->title,
                'description' => $issue->recommendation ?? $issue->description,
                'tab' => 'Audit Results',
            ];
        }

        foreach ($issues->where('severity', 'high')->take(3) as $issue) {
            $actions[] = [
                'category' => 'audit',
                'severity' => 'high',
                'impact' => 72,
                'title' => $issue->title,
                'description' => $issue->recommendation ?? $issue->description,
                'tab' => 'Audit Results',
            ];
        }

        return $actions;
    }

    private function generateSummary(int $healthScore, array $actions): string
    {
        $criticalCount = count(array_filter($actions, fn ($a) => $a['severity'] === 'critical'));
        $highCount = count(array_filter($actions, fn ($a) => $a['severity'] === 'high'));

        if ($healthScore >= 80 && $criticalCount === 0) {
            return "Site-ul are o sanatate SEO buna (scor {$healthScore}/100). Continua sa monitorizezi si optimizeaza oportunitatile de continut.";
        }

        if ($healthScore >= 50) {
            return "Site-ul necesita atentie (scor {$healthScore}/100). Ai {$criticalCount} probleme critice si {$highCount} probleme importante de rezolvat.";
        }

        return "Site-ul are probleme SEO serioase (scor {$healthScore}/100). Rezolva urgent cele {$criticalCount} probleme critice si {$highCount} probleme importante.";
    }
}
