<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class ErrorLogGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'error_logs';

    /**
     * Below this, a plugin's errors are noise rather than a story worth telling a
     * client about. "3 warnings from a plugin" is not a paragraph.
     */
    private const NARRATIVE_MIN_OCCURRENCES = 25;

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $baseQuery = fn () => PhpErrorLog::where('site_id', $site->id)
            ->whereBetween('last_seen_at', [$periodStart, $periodEnd]);

        $errors = $baseQuery()->get();

        if ($errors->isEmpty()) {
            return [
                'available' => true,
                'total_count' => 0,
                'fatal_count' => 0,
                'warning_count' => 0,
                'unresolved_count' => 0,
                'top_errors' => [],
            ];
        }

        $totalCount = $errors->count();
        $fatalCount = $errors->where('level', 'fatal')->count();
        $warningCount = $errors->where('level', 'warning')->count();
        $unresolvedCount = $errors->where('is_resolved', false)->count();

        $topErrors = $errors->sortByDesc('count')
            ->take(5)
            ->map(fn (PhpErrorLog $error) => [
                'message' => $error->message,
                'level' => $error->level,
                'count' => $error->count,
                'last_seen_at' => $error->last_seen_at,
            ])
            ->values()
            ->toArray();

        return [
            'available' => true,
            'total_count' => $totalCount,
            'fatal_count' => $fatalCount,
            'warning_count' => $warningCount,
            'unresolved_count' => $unresolvedCount,
            'top_errors' => $topErrors,
            'narratives' => $this->narratives($site, $errors, $periodStart, $periodEnd),
        ];
    }

    /**
     * SPEC §12.4 — "cifra care justifică factura".
     *
     * "Am identificat 847 de avertismente generate de JetWooBuilder 2.1.3. Am
     * raportat problema dezvoltatorului și am aplicat versiunea 2.1.4 pe 18 iulie.
     * Erori active în prezent: 0."
     *
     * Everything this needs already existed and never met: the connector attributes
     * each error to a plugin or theme (php_error_logs.source_slug, SPEC §5.6) and
     * update_logs records what was applied and when. This gatherer emitted only raw
     * counters, dropping the attribution at precisely the point it was needed, so
     * the one section that demonstrates preventive work said nothing about it.
     *
     * Facts only. The sentence is assembled in the view from these numbers — the
     * report never claims a fix caused the silence, only that the update happened
     * and that the errors have or have not stopped.
     *
     * @param  \Illuminate\Support\Collection<int, PhpErrorLog>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function narratives(Site $site, $errors, Carbon $periodStart, Carbon $periodEnd): array
    {
        $attributed = $errors
            ->filter(fn (PhpErrorLog $e) => filled($e->source_slug))
            ->groupBy('source_slug');

        $out = [];

        foreach ($attributed as $slug => $group) {
            $occurrences = (int) $group->sum(fn (PhpErrorLog $e) => $e->count ?? 1);

            if ($occurrences < self::NARRATIVE_MIN_OCCURRENCES) {
                continue;
            }

            // Was this plugin or theme updated during the period? If so, the report
            // can say what was applied and when.
            $update = UpdateLog::where('site_id', $site->id)
                ->where('slug', $slug)
                ->where('success', true)
                ->whereBetween('performed_at', [$periodStart, $periodEnd])
                ->orderByDesc('performed_at')
                ->first();

            // Still occurring AFTER that update is the honest closing number, and
            // the reason the sentence cannot simply claim the problem is solved.
            $stillActive = $update
                ? (int) PhpErrorLog::where('site_id', $site->id)
                    ->where('source_slug', $slug)
                    ->where('last_seen_at', '>', $update->performed_at)
                    ->sum('count')
                : $occurrences;

            $out[] = [
                'source_type' => $group->first()->source_type ?? 'plugin',
                'source_slug' => $slug,
                'name' => $update?->name ?: $slug,
                'occurrences' => $occurrences,
                'level' => $group->sortByDesc('count')->first()->level,
                'updated_from' => $update?->from_version,
                'updated_to' => $update?->to_version,
                'updated_at' => $update?->performed_at,
                'still_active' => $stillActive,
            ];
        }

        usort($out, fn (array $a, array $b) => $b['occurrences'] <=> $a['occurrences']);

        return array_slice($out, 0, 3);
    }
}
