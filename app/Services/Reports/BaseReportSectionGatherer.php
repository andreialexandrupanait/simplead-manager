<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\ReportSectionGathererInterface;

abstract class BaseReportSectionGatherer implements ReportSectionGathererInterface
{
    protected string $sectionKey = '';

    public function supports(string $sectionKey): bool
    {
        return $this->sectionKey === $sectionKey;
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

        $change = $current - $previous;
        $percentChange = ($change / abs($previous)) * 100;

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

        return number_format($value, $decimals, $decimalSep, $thousandsSep);
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }

    protected function formatDuration(int $minutes): string
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

    protected function getUptimeStatus(?float $pct): string
    {
        if ($pct === null) {
            return 'neutral';
        }

        return $pct >= 99.5 ? 'good' : ($pct >= 95 ? 'warning' : 'danger');
    }

    protected function getScoreStatus(?float $score): string
    {
        if ($score === null) {
            return 'neutral';
        }

        return $score >= 90 ? 'good' : ($score >= 50 ? 'warning' : 'danger');
    }
}
