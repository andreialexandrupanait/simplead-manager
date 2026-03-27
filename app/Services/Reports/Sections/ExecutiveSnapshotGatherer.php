<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class ExecutiveSnapshotGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'executive_snapshot';

    private array $accumulatedData = [];

    private array $excludedSections = [];

    private ?ReportTemplate $template = null;

    public function withData(array $data): static
    {
        $this->accumulatedData = $data;

        return $this;
    }

    public function withExcludedSections(array $excludedSections): static
    {
        $this->excludedSections = $excludedSections;

        return $this;
    }

    public function withTemplate(ReportTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $uptime = $this->accumulatedData['uptime'] ?? [];
        $updates = $this->accumulatedData['updates'] ?? [];
        $backups = $this->accumulatedData['backups'] ?? [];
        $perf = $this->accumulatedData['performance'] ?? [];
        $analytics = $this->accumulatedData['analytics'] ?? [];
        $sc = $this->accumulatedData['search_console'] ?? [];

        $uptimePct = $uptime['uptime_percentage'] ?? null;
        $downtimeMin = $uptime['total_downtime_minutes'] ?? null;
        $incidentsCount = $uptime['incidents_count'] ?? 0;
        $pluginUpdates = $updates['total_count'] ?? 0;
        $backupCount = $backups['count'] ?? 0;
        $backupTotal = ($backups['count'] ?? 0) + ($backups['failed_count'] ?? 0);
        $desktopScore = $perf['desktop_score'] ?? null;
        $mobileScore = $perf['mobile_score'] ?? null;
        $totalUsers = $analytics['total_users'] ?? null;
        $impressions = $sc['overview']['total_impressions'] ?? null;

        $allCards = [
            [
                'key' => 'uptime',
                'value' => $uptimePct !== null ? $this->formatNumber($uptimePct, 2, $language).'%' : __('report.snapshot_no_data', [], $language),
                'label' => __('report.snapshot_uptime', [], $language),
                'note' => $incidentsCount > 0 ? __('report.snapshot_incidents', ['count' => $incidentsCount], $language) : null,
                'status' => $this->getUptimeStatus($uptimePct),
            ],
            [
                'key' => 'downtime',
                'value' => $this->formatDuration($downtimeMin ?? 0),
                'label' => __('report.snapshot_downtime', [], $language),
                'note' => null,
                'status' => $downtimeMin > 0 ? 'warning' : 'good',
            ],
            [
                'key' => 'updates',
                'value' => (string) $pluginUpdates,
                'label' => __('report.snapshot_updates', [], $language),
                'note' => null,
                'status' => 'neutral',
            ],
            [
                'key' => 'backups',
                'value' => (string) $backupCount,
                'label' => __('report.snapshot_backups', [], $language),
                'note' => $backupTotal > 0 ? __('report.snapshot_of_total', ['total' => $backupTotal], $language) : null,
                'status' => ($backups['failed_count'] ?? 0) > 0 ? 'danger' : 'good',
            ],
            [
                'key' => 'desktop_perf',
                'value' => $desktopScore !== null ? (string) $desktopScore : __('report.snapshot_no_data', [], $language),
                'label' => __('report.snapshot_desktop_perf', [], $language),
                'note' => null,
                'status' => $this->getScoreStatus($desktopScore),
            ],
            [
                'key' => 'mobile_perf',
                'value' => $mobileScore !== null ? (string) $mobileScore : __('report.snapshot_no_data', [], $language),
                'label' => __('report.snapshot_mobile_perf', [], $language),
                'note' => null,
                'status' => $this->getScoreStatus($mobileScore),
            ],
            [
                'key' => 'users',
                'value' => $totalUsers !== null ? $this->formatNumber($totalUsers, 0, $language) : __('report.snapshot_no_data', [], $language),
                'label' => __('report.snapshot_users', [], $language),
                'note' => null,
                'status' => 'neutral',
            ],
            [
                'key' => 'impressions',
                'value' => $impressions !== null ? $this->formatNumber($impressions, 0, $language) : __('report.snapshot_no_data', [], $language),
                'label' => __('report.snapshot_impressions', [], $language),
                'note' => null,
                'status' => 'neutral',
            ],
        ];

        // Filter out excluded overview cards (keys like "overview:uptime", "overview:downtime", etc.)
        $excludedCardKeys = collect($this->excludedSections)
            ->filter(fn ($s) => str_starts_with($s, 'overview:'))
            ->map(fn ($s) => str_replace('overview:', '', $s))
            ->toArray();

        if (! empty($excludedCardKeys)) {
            $allCards = array_values(array_filter($allCards, fn ($card) => ! in_array($card['key'], $excludedCardKeys)));
        }

        // Filter by template section_options for executive_snapshot
        $snapshotOptions = $this->template?->section_options['executive_snapshot'] ?? [];
        $allCards = array_values(array_filter($allCards, function ($card) use ($snapshotOptions) {
            $optionKey = 'show_'.$card['key'];

            return ($snapshotOptions[$optionKey] ?? true) !== false;
        }));

        return $allCards;
    }
}
