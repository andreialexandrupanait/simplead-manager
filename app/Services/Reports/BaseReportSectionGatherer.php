<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\ReportSectionGathererInterface;
use Carbon\Carbon;

abstract class BaseReportSectionGatherer implements ReportSectionGathererInterface
{
    /**
     * P2-03: a cached Google dataset older than this (relative to generation time)
     * is considered stale even if it nominally covers the report period.
     */
    protected const GOOGLE_STALE_AFTER_DAYS = 7;

    protected string $sectionKey = '';

    public function supports(string $sectionKey): bool
    {
        return $this->sectionKey === $sectionKey;
    }

    // ─── Google Data Freshness (P2-03) ───────────────────────────────

    /**
     * Describe how well a cached Google (Analytics / Search Console) dataset matches
     * the report period so the report can label the actual window and flag stale or
     * wrong-window data, instead of silently presenting a rolling 28-day cache as if
     * it were the report's real period.
     *
     * @return array{data_period_start: ?string, data_period_end: ?string, data_covers_period: bool, data_is_stale: bool, data_fetched_at: ?string}
     */
    protected function googleDataMeta(
        ?Carbon $dataStart,
        ?Carbon $dataEnd,
        ?Carbon $fetchedAt,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $coversPeriod = $dataStart !== null
            && $dataEnd !== null
            && $dataStart->lessThanOrEqualTo($periodStart)
            && $dataEnd->greaterThanOrEqualTo($periodEnd);

        // Stale when: the data does not cover the report period, OR it was fetched
        // before the period ended (so it cannot reflect the full window), OR it is
        // simply old relative to when the report is being generated.
        $isStale = ! $coversPeriod
            || ($fetchedAt !== null && $fetchedAt->lessThan($periodEnd))
            || ($fetchedAt !== null && $fetchedAt->lessThan(Carbon::now()->subDays(static::GOOGLE_STALE_AFTER_DAYS)));

        return [
            'data_period_start' => $dataStart?->format('d.m.Y'),
            'data_period_end' => $dataEnd?->format('d.m.Y'),
            'data_covers_period' => $coversPeriod,
            'data_is_stale' => $isStale,
            'data_fetched_at' => $fetchedAt?->format('d.m.Y H:i'),
        ];
    }

    // ─── Trend Helpers ───────────────────────────────────────────────

    protected function calculateTrend(float|int|string|null $current, float|int|string|null $previous): array
    {
        if ($previous === null || $previous == 0) {
            return [
                'direction' => 'neutral',
                'value' => null,
                'display' => '',
                'color' => '#6b7280',
            ];
        }

        $change = (float) $current - (float) $previous;
        $percentChange = ($change / abs((float) $previous)) * 100;

        if (abs($percentChange) < 0.5) {
            return [
                'direction' => 'neutral',
                'value' => 0,
                'display' => '0%',
                'color' => '#6b7280',
            ];
        }

        $isPositive = $change > 0;

        return [
            'direction' => $isPositive ? 'up' : 'down',
            'value' => round($percentChange, 1),
            'display' => ($isPositive ? '↑' : '↓').' '.abs(round($percentChange, 1)).'%',
            'color' => $isPositive ? '#10b981' : '#ef4444',
        ];
    }

    protected function calculateTrendInverse(float|int|string|null $current, float|int|string|null $previous): array
    {
        $trend = $this->calculateTrend($current, $previous);

        if ($trend['direction'] === 'up') {
            $trend['color'] = '#ef4444';
        } elseif ($trend['direction'] === 'down') {
            $trend['color'] = '#10b981';
        }

        return $trend;
    }

    // ─── Number Formatting ───────────────────────────────────────────

    protected function formatNumber(float|int|string|null $value, int $decimals = 0, string $language = 'ro'): string
    {
        if ($value === null) {
            return __('report.not_available', [], $language);
        }

        $decimalSep = $language === 'ro' ? ',' : '.';
        $thousandsSep = $language === 'ro' ? '.' : ',';

        return number_format((float) $value, $decimals, $decimalSep, $thousandsSep);
    }

    protected function formatBytes(int|float|string $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }

    protected function formatDuration(int|float|string $minutes): string
    {
        if ($minutes <= 0) {
            return '0 min';
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $remaining > 0 ? "{$hours}h {$remaining}min" : "{$hours}h";
    }

    // ─── Status Helpers ──────────────────────────────────────────────

    protected function getUptimeStatus(float|int|string|null $pct): string
    {
        if ($pct === null) {
            return 'neutral';
        }

        $pct = (float) $pct;

        return $pct >= 99.5 ? 'good' : ($pct >= 95 ? 'warning' : 'danger');
    }

    protected function getScoreStatus(float|int|string|null $score): string
    {
        if ($score === null) {
            return 'neutral';
        }

        $score = (float) $score;

        return $score >= 90 ? 'good' : ($score >= 50 ? 'warning' : 'danger');
    }
}
