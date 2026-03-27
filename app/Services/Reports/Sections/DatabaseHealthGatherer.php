<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\DatabaseHealthCheck;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class DatabaseHealthGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'database_health';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $check = DatabaseHealthCheck::where('site_id', $site->id)
            ->latest('checked_at')
            ->first();

        if (! $check) {
            return [];
        }

        $largestTables = collect($check->largest_tables ?? [])->take(5)->map(fn ($t) => [
            'name' => $t['table'] ?? $t['name'] ?? '—',
            'rows' => $t['rows'] ?? 0,
            'data_size' => $t['data_length'] ?? $t['data_size'] ?? $t['size'] ?? 0,
            'index_size' => $t['index_length'] ?? $t['index_size'] ?? 0,
        ])->toArray();

        $tablesWithOverhead = collect($check->tables_with_overhead ?? [])->map(fn ($t) => [
            'name' => $t['table'] ?? $t['name'] ?? '—',
            'overhead' => $t['data_free'] ?? $t['overhead'] ?? 0,
        ])->toArray();

        return [
            'total_size' => $check->formatted_total_size,
            'total_size_raw' => $check->total_size,
            'total_tables' => $check->total_tables,
            'autoload_size' => $check->formatted_autoload_size,
            'autoload_size_raw' => $check->autoload_size,
            'myisam_count' => $check->myisam_count,
            'status' => $check->status,
            'status_label' => $check->status_label,
            'status_color' => $check->status_color,
            'issues' => $check->issues,
            'largest_tables' => $largestTables,
            'tables_with_overhead' => $tablesWithOverhead,
            'checked_at' => $check->checked_at?->format('d/m/Y H:i'),
        ];
    }
}
