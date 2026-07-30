<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\SiteTask;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

/**
 * SPEC §12.2 point 3 — "Mentenanță efectuată — update-uri, backupuri, restore
 * verificat, sarcini finalizate din app.simplead.ro".
 *
 * The work actually done on the site during the period, pulled from
 * app.simplead.ro (§11). This is the half of the report that demonstrates
 * preventive effort — §12.4's "singura secțiune care demonstrează muncă
 * preventivă. Restul spun «nu s-a stricat nimic»".
 *
 * Operational fields only: what was done, by whom, when. The upstream rates and
 * invoice statuses are dropped at ingest (SPEC §1 keeps money in SAD Hub), so a
 * client report cannot leak a partner rate even by accident.
 */
class TasksGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'tasks';

    /** Never print an unbounded list into a PDF. */
    private const MAX_LISTED = 40;

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        // A site that was never linked to a project upstream simply has no task
        // section — that is a deliberate omission, not a failed integration
        // (§12.3 barrier 3).
        if ($site->external_project_id === null || $site->external_project_id === '') {
            return $this->integrationStatus(configured: false, hasData: false);
        }

        $completed = SiteTask::query()
            ->where('site_id', $site->id)
            ->completedBetween($periodStart, $periodEnd)
            ->orderBy('status_changed_at')
            ->get();

        // Linked but nothing finished this month is a real answer, not a hole:
        // report it as an empty month rather than holding the whole report.
        $rows = $completed->take(self::MAX_LISTED)->map(fn (SiteTask $task): array => [
            'name' => $task->name,
            'type' => $task->task_type,
            'assignee' => $task->assignee_name,
            'completed_at' => $task->status_changed_at?->format('d.m.Y'),
        ])->values()->all();

        return $this->integrationStatus(configured: true, hasData: true) + [
            'available' => true,
            'completed_count' => $completed->count(),
            'listed' => $rows,
            'truncated' => max(0, $completed->count() - count($rows)),
            'period_label' => $periodStart->format('d.m.Y').' – '.$periodEnd->format('d.m.Y'),
        ];
    }
}
