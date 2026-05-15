<?php

declare(strict_types=1);

namespace App\Services\SeoAudit;

use App\Models\SeoAudit;

class ScoringService
{
    public function calculateScores(SeoAudit $audit): array
    {
        $weights = config('seo.scoring.weights');
        $gp = ['technical' => 0, 'on_page' => 0, 'performance' => 0, 'other' => 0];
        foreach ($audit->issues()->get() as $issue) {
            $gp[$issue->category->scoringGroup()] += $issue->severity->penalty();
        }
        $cs = [];
        foreach ($gp as $g => $p) {
            $cs[$g] = max(0, 100 - $p);
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
