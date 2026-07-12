<?php

declare(strict_types=1);

namespace App\Services\SeoAudit;

use App\Models\SeoAudit;

class ScoringService
{
    public function calculateScores(SeoAudit $audit): array
    {
        $weights = config('seo.scoring.weights');

        // P2-17: per-page penalties must be AVERAGED across the crawled pages,
        // not summed unbounded. Summing meant a large site accumulated enough
        // penalty from page count alone to saturate every category to 0 even
        // when each page was only slightly imperfect. Site-wide issues
        // (url IS NULL — missing robots.txt, SSL, sitemap, …) occur once and
        // keep their full penalty; per-page issues are divided by the page
        // count so the score reflects AVERAGE page quality, independent of how
        // many pages the site has.
        $pageCount = max(1, $audit->pages()->count());

        $perPage = ['technical' => 0, 'on_page' => 0, 'performance' => 0, 'other' => 0];
        $siteWide = ['technical' => 0, 'on_page' => 0, 'performance' => 0, 'other' => 0];
        foreach ($audit->issues()->get() as $issue) {
            $group = $issue->category->scoringGroup();
            if ($issue->url === null) {
                $siteWide[$group] += $issue->severity->penalty();
            } else {
                $perPage[$group] += $issue->severity->penalty();
            }
        }

        $cs = [];
        foreach ($perPage as $g => $p) {
            $penalty = ($p / $pageCount) + $siteWide[$g];
            $cs[$g] = (int) max(0, round(100 - $penalty));
        }
        $pm = $audit->site->performanceMonitor;
        if ($pm) {
            $m = $pm->latest_mobile_score;
            $d = $pm->latest_desktop_score;
            if ($m !== null && $d !== null) {
                $cs['performance'] = (int) round($cs['performance'] * 0.6 + (($m + $d) / 2) * 0.4);
            }
        }
        $o = 0;
        $tw = array_sum($weights);
        foreach ($weights as $g => $w) {
            $o += (($cs[$g] ?? 50) * $w) / $tw;
        }

        return ['overall' => (int) round($o), 'categories' => $cs];
    }
}
