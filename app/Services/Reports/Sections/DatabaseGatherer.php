<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\DatabaseCleanup;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class DatabaseGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'database';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $cleanups = DatabaseCleanup::where('site_id', $site->id)
            ->whereBetween('cleaned_at', [$periodStart, $periodEnd])
            ->get();

        if ($cleanups->isEmpty()) {
            return [];
        }

        $latestCleanup = $cleanups->sortByDesc('cleaned_at')->first();

        return [
            'total_saved' => $cleanups->sum('space_saved'),
            'last_cleanup_date' => $latestCleanup->cleaned_at,
            'categories' => [
                ['key' => 'revisions', 'deleted' => $cleanups->sum('revisions_deleted'), 'saved' => $cleanups->sum('revisions_saved')],
                ['key' => 'auto_drafts', 'deleted' => $cleanups->sum('auto_drafts_deleted'), 'saved' => $cleanups->sum('auto_drafts_saved')],
                ['key' => 'trashed', 'deleted' => $cleanups->sum('trash_posts_deleted'), 'saved' => $cleanups->sum('trash_posts_saved')],
                ['key' => 'spam', 'deleted' => $cleanups->sum('spam_comments_deleted'), 'saved' => $cleanups->sum('spam_comments_saved')],
                ['key' => 'trash_comments', 'deleted' => $cleanups->sum('trash_comments_deleted'), 'saved' => $cleanups->sum('trash_comments_saved')],
                ['key' => 'transients', 'deleted' => $cleanups->sum('transients_deleted'), 'saved' => $cleanups->sum('transients_saved')],
                ['key' => 'orphaned', 'deleted' => $cleanups->sum('orphaned_meta_deleted'), 'saved' => $cleanups->sum('orphaned_saved')],
            ],
        ];
    }
}
