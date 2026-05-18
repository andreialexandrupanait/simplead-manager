<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\DnsChange;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class DnsGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'dns';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $monitor = $site->dnsMonitor;
        if (! $monitor) {
            return ['available' => false];
        }

        $changes = DnsChange::where('dns_monitor_id', $monitor->id)
            ->whereBetween('detected_at', [$periodStart, $periodEnd])
            ->orderBy('detected_at', 'desc')
            ->get();

        $currentRecords = $monitor->current_records ?? [];

        $txtRecords = (array) ($currentRecords['TXT'] ?? []);

        $hasSpf = collect($txtRecords)->contains(
            fn (string $record) => str_contains($record, 'v=spf1')
        );

        $hasDmarc = ! empty($currentRecords['DMARC'] ?? []);
        $hasDkim = ! empty($currentRecords['DKIM'] ?? []);

        $topChanges = $changes->take(10)->map(fn (DnsChange $change) => [
            'record_type' => $change->record_type,
            'old_value' => $change->old_value,
            'new_value' => $change->new_value,
            'detected_at' => $change->detected_at,
        ])->toArray();

        return [
            'available' => true,
            'domain' => $monitor->domain,
            'current_records' => $currentRecords,
            'has_spf' => $hasSpf,
            'has_dmarc' => $hasDmarc,
            'has_dkim' => $hasDkim,
            'changes_count' => $changes->count(),
            'changes' => $topChanges,
        ];
    }
}
