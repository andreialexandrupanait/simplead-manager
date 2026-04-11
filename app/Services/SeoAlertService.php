<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\KeywordPosition;
use App\Models\PerformanceTest;
use App\Models\SeoAlertRule;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class SeoAlertService
{
    public function evaluateAlerts(Site $site): int
    {
        $rules = SeoAlertRule::where('site_id', $site->id)
            ->active()
            ->get();

        $triggered = 0;

        foreach ($rules as $rule) {
            if (! $rule->canTrigger()) {
                continue;
            }

            $details = $this->evaluateRule($site, $rule);

            if ($details !== null) {
                $this->triggerAlert($site, $rule, $details);
                $triggered++;
            }
        }

        return $triggered;
    }

    public function createDefaultRules(Site $site): void
    {
        foreach (SeoAlertRule::TYPES as $type) {
            SeoAlertRule::firstOrCreate(
                ['site_id' => $site->id, 'rule_type' => $type],
                [
                    'threshold' => SeoAlertRule::defaultThreshold($type),
                    'is_active' => true,
                    'cooldown_minutes' => 1440,
                ],
            );
        }
    }

    private function evaluateRule(Site $site, SeoAlertRule $rule): ?array
    {
        try {
            return match ($rule->rule_type) {
                SeoAlertRule::TYPE_POSITION_DROP => $this->checkPositionDrops($site, $rule),
                SeoAlertRule::TYPE_TRAFFIC_DROP => $this->checkTrafficDrop($site, $rule),
                SeoAlertRule::TYPE_INDEXING_CHANGE => $this->checkIndexingChanges($site, $rule),
                SeoAlertRule::TYPE_SCORE_DROP => $this->checkScoreDrop($site, $rule),
                SeoAlertRule::TYPE_PAGE_ERROR => $this->checkPageErrors($site, $rule),
                SeoAlertRule::TYPE_CWV_REGRESSION => $this->checkCwvRegression($site, $rule),
                default => null,
            };
        } catch (\Exception $e) {
            Log::warning("SEO alert evaluation failed for rule {$rule->id}: {$e->getMessage()}");

            return null;
        }
    }

    private function checkPositionDrops(Site $site, SeoAlertRule $rule): ?array
    {
        $threshold = $rule->threshold;
        $positionDrop = $threshold['positions'] ?? 5;
        $minImpressions = $threshold['min_impressions'] ?? 10;

        $keywords = $site->trackedKeywords()->get();
        $droppedKeywords = [];

        foreach ($keywords as $keyword) {
            $positions = $keyword->positions()
                ->orderByDesc('date')
                ->limit(2)
                ->get();

            if ($positions->count() < 2) {
                continue;
            }

            $current = $positions->first();
            $previous = $positions->last();

            if ($current->impressions < $minImpressions) {
                continue;
            }

            $drop = $current->position - $previous->position;

            if ($drop >= $positionDrop) {
                $droppedKeywords[] = [
                    'keyword' => $keyword->keyword,
                    'from' => round($previous->position, 1),
                    'to' => round($current->position, 1),
                    'drop' => round($drop, 1),
                ];
            }
        }

        if (empty($droppedKeywords)) {
            return null;
        }

        return [
            'keywords' => $droppedKeywords,
            'count' => count($droppedKeywords),
        ];
    }

    private function checkTrafficDrop(Site $site, SeoAlertRule $rule): ?array
    {
        $threshold = $rule->threshold;
        $dropPercent = $threshold['drop_percent'] ?? 20;
        $minClicks = $threshold['min_clicks'] ?? 50;

        // Compare last 7 days vs previous 7 days using keyword position data
        $recentClicks = KeywordPosition::whereIn(
            'tracked_keyword_id',
            $site->trackedKeywords()->pluck('id'),
        )
            ->where('date', '>=', now()->subDays(10)->toDateString())
            ->where('date', '<', now()->subDays(3)->toDateString())
            ->sum('clicks');

        $previousClicks = KeywordPosition::whereIn(
            'tracked_keyword_id',
            $site->trackedKeywords()->pluck('id'),
        )
            ->where('date', '>=', now()->subDays(17)->toDateString())
            ->where('date', '<', now()->subDays(10)->toDateString())
            ->sum('clicks');

        if ($previousClicks < $minClicks) {
            return null;
        }

        $dropPct = (($previousClicks - $recentClicks) / $previousClicks) * 100;

        if ($dropPct < $dropPercent) {
            return null;
        }

        return [
            'previous_clicks' => $previousClicks,
            'recent_clicks' => $recentClicks,
            'drop_percent' => round($dropPct, 1),
        ];
    }

    private function checkIndexingChanges(Site $site, SeoAlertRule $rule): ?array
    {
        $threshold = $rule->threshold;
        $dropCount = $threshold['drop_count'] ?? 5;

        // Compare latest two audits' data for index coverage
        $audits = SeoAudit::where('site_id', $site->id)
            ->orderByDesc('scanned_at')
            ->limit(2)
            ->get();

        if ($audits->count() < 2) {
            return null;
        }

        $current = $audits->first();
        $previous = $audits->last();

        $currentIndexed = $current->data['index_coverage'] ?? [];
        $previousIndexed = $previous->data['index_coverage'] ?? [];

        $currentValid = collect($currentIndexed)->where('verdict', 'PASS')->count();
        $previousValid = collect($previousIndexed)->where('verdict', 'PASS')->count();

        $drop = $previousValid - $currentValid;

        if ($drop < $dropCount) {
            return null;
        }

        return [
            'previous_indexed' => $previousValid,
            'current_indexed' => $currentValid,
            'drop' => $drop,
        ];
    }

    private function checkScoreDrop(Site $site, SeoAlertRule $rule): ?array
    {
        $threshold = $rule->threshold;
        $dropPoints = $threshold['drop_points'] ?? 10;

        $audits = SeoAudit::where('site_id', $site->id)
            ->orderByDesc('scanned_at')
            ->limit(2)
            ->get();

        if ($audits->count() < 2) {
            return null;
        }

        $current = $audits->first();
        $previous = $audits->last();

        $drop = $previous->score - $current->score;

        if ($drop < $dropPoints) {
            return null;
        }

        return [
            'previous_score' => $previous->score,
            'current_score' => $current->score,
            'drop' => $drop,
        ];
    }

    private function checkPageErrors(Site $site, SeoAlertRule $rule): ?array
    {
        $threshold = $rule->threshold;
        $errorCodes = $threshold['error_codes'] ?? [500, 502, 503];

        $latestCrawl = $site->siteCrawls()
            ->where('status', 'completed')
            ->latest()
            ->first();

        if (! $latestCrawl) {
            return null;
        }

        $errorPages = $latestCrawl->pages()
            ->whereIn('status_code', $errorCodes)
            ->get(['url', 'status_code']);

        if ($errorPages->isEmpty()) {
            return null;
        }

        return [
            'error_pages' => $errorPages->map(fn ($p) => [
                'url' => $p->url,
                'status_code' => $p->status_code,
            ])->take(10)->all(),
            'count' => $errorPages->count(),
        ];
    }

    private function checkCwvRegression(Site $site, SeoAlertRule $rule): ?array
    {
        $threshold = $rule->threshold;
        $lcpAbove = $threshold['lcp_above'] ?? 4.0;
        $clsAbove = $threshold['cls_above'] ?? 0.25;
        $inpAbove = $threshold['inp_above'] ?? 500;

        $latestTest = PerformanceTest::where('site_id', $site->id)
            ->where('status', 'completed')
            ->where('device', 'mobile')
            ->latest('tested_at')
            ->first();

        if (! $latestTest) {
            return null;
        }

        $issues = [];

        if ($latestTest->field_lcp !== null && $latestTest->field_lcp > $lcpAbove) {
            $issues[] = "LCP: {$latestTest->field_lcp}s (threshold: {$lcpAbove}s)";
        } elseif ($latestTest->lcp !== null && $latestTest->lcp > $lcpAbove) {
            $issues[] = "LCP (lab): {$latestTest->lcp}s (threshold: {$lcpAbove}s)";
        }

        if ($latestTest->field_cls !== null && $latestTest->field_cls > $clsAbove) {
            $issues[] = "CLS: {$latestTest->field_cls} (threshold: {$clsAbove})";
        } elseif ($latestTest->cls !== null && $latestTest->cls > $clsAbove) {
            $issues[] = "CLS (lab): {$latestTest->cls} (threshold: {$clsAbove})";
        }

        if ($latestTest->field_inp !== null && $latestTest->field_inp > $inpAbove) {
            $issues[] = "INP: {$latestTest->field_inp}ms (threshold: {$inpAbove}ms)";
        }

        if (empty($issues)) {
            return null;
        }

        return [
            'issues' => $issues,
            'test_id' => $latestTest->id,
            'tested_at' => $latestTest->tested_at?->toDateTimeString(),
        ];
    }

    private function triggerAlert(Site $site, SeoAlertRule $rule, array $details): void
    {
        $typeLabel = SeoAlertRule::typeLabel($rule->rule_type);
        $message = $this->buildMessage($rule->rule_type, $details);

        NotificationService::notifySiteEvent(
            site: $site,
            event: "seo_alert_{$rule->rule_type}",
            title: "SEO Alert: {$typeLabel}",
            message: $message,
            fields: $this->buildFields($rule->rule_type, $details),
            severity: $this->getSeverity($rule->rule_type, $details),
        );

        $rule->update(['last_triggered_at' => now()]);

        ActivityLogger::log(
            type: 'seo',
            severity: 'warning',
            title: "SEO Alert: {$typeLabel} for {$site->name}",
            description: $message,
            site: $site,
            metadata: ['rule_id' => $rule->id, 'rule_type' => $rule->rule_type, 'details' => $details],
            icon: 'bell-alert',
        );
    }

    private function buildMessage(string $type, array $details): string
    {
        return match ($type) {
            SeoAlertRule::TYPE_POSITION_DROP => "{$details['count']} keyword(s) dropped significantly in SERP positions.",
            SeoAlertRule::TYPE_TRAFFIC_DROP => "Organic traffic dropped by {$details['drop_percent']}% compared to the previous period.",
            SeoAlertRule::TYPE_INDEXING_CHANGE => "Indexed pages decreased by {$details['drop']} (from {$details['previous_indexed']} to {$details['current_indexed']}).",
            SeoAlertRule::TYPE_SCORE_DROP => "SEO score dropped from {$details['previous_score']} to {$details['current_score']} (-{$details['drop']} points).",
            SeoAlertRule::TYPE_PAGE_ERROR => "{$details['count']} page(s) returning server errors detected in latest crawl.",
            SeoAlertRule::TYPE_CWV_REGRESSION => 'Core Web Vitals regression detected: '.implode(', ', $details['issues']),
            default => 'SEO alert triggered.',
        };
    }

    private function buildFields(string $type, array $details): array
    {
        return match ($type) {
            SeoAlertRule::TYPE_POSITION_DROP => [
                'Keywords affected' => (string) $details['count'],
                'Top drop' => ! empty($details['keywords'])
                    ? "{$details['keywords'][0]['keyword']} ({$details['keywords'][0]['from']} → {$details['keywords'][0]['to']})"
                    : 'N/A',
            ],
            SeoAlertRule::TYPE_TRAFFIC_DROP => [
                'Previous clicks' => (string) $details['previous_clicks'],
                'Recent clicks' => (string) $details['recent_clicks'],
                'Drop' => "{$details['drop_percent']}%",
            ],
            SeoAlertRule::TYPE_SCORE_DROP => [
                'Previous' => "{$details['previous_score']}/100",
                'Current' => "{$details['current_score']}/100",
            ],
            SeoAlertRule::TYPE_PAGE_ERROR => [
                'Error pages' => (string) $details['count'],
            ],
            default => [],
        };
    }

    private function getSeverity(string $type, array $details): string
    {
        return match ($type) {
            SeoAlertRule::TYPE_POSITION_DROP => $details['count'] >= 5 ? 'critical' : 'warning',
            SeoAlertRule::TYPE_TRAFFIC_DROP => $details['drop_percent'] >= 50 ? 'critical' : 'warning',
            SeoAlertRule::TYPE_SCORE_DROP => $details['drop'] >= 20 ? 'critical' : 'warning',
            SeoAlertRule::TYPE_PAGE_ERROR => $details['count'] >= 5 ? 'critical' : 'warning',
            SeoAlertRule::TYPE_CWV_REGRESSION => 'warning',
            SeoAlertRule::TYPE_INDEXING_CHANGE => 'warning',
            default => 'warning',
        };
    }
}
