<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\LinkCheckResult;
use App\Models\LinkCheckRun;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

/**
 * SPEC §5.7 — broken links, reported as the CHANGE, not the state.
 *
 * "3 linkuri rupte noi, 2 rezolvate față de luna trecută." The scan computed both
 * counts and stored them on the run from the day it was written; nothing ever read
 * them back. There was no screen and no report section, so a module that ran every
 * month produced nothing anybody could see.
 *
 * "O listă de 40 de linkuri rupte, identică lună de lună, nu se citește niciodată"
 * — hence the headline is the difference, and the list is capped and secondary.
 */
class BrokenLinksGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'broken_links';

    /** Never print an unbounded list into a PDF. */
    private const MAX_LISTED = 20;

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $run = LinkCheckRun::where('site_id', $site->id)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<=', $periodEnd)
            ->orderByDesc('finished_at')
            ->first();

        // No scan has ever completed for this site — the section is omitted rather
        // than printed as a row of zeros, which would read as "we checked, all clean".
        if (! $run) {
            return $this->integrationStatus(configured: false, hasData: false);
        }

        $broken = LinkCheckResult::where('run_id', $run->id)
            ->where('is_broken', true)
            ->orderBy('url')
            ->limit(self::MAX_LISTED)
            ->get();

        return [
            'scanned_at' => $run->finished_at,
            'total_urls' => $run->total_urls,
            'broken_count' => $run->broken_count,
            'chains_count' => $run->chains_count,
            'new_broken_count' => $run->new_broken_count,
            'resolved_count' => $run->resolved_count,
            'unchanged' => $run->new_broken_count === 0 && $run->resolved_count === 0,
            'listed' => $broken->map(fn (LinkCheckResult $result): array => [
                'url' => $result->url,
                'source_page' => $result->source_page,
                'status_code' => $result->status_code,
            ])->all(),
            'listed_of' => $run->broken_count,
        ];
    }
}
