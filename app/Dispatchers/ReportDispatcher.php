<?php

namespace App\Dispatchers;

use App\Jobs\GenerateReport;
use App\Models\ReportSchedule;

class ReportDispatcher
{
    /**
     * Dispatch due report generation jobs.
     * No circuit breaker — reports don't call WP API.
     * Called every 5 minutes from the scheduler.
     */
    public function __invoke(): void
    {
        ReportSchedule::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->with(['site', 'reportTemplate'])
            ->each(function (ReportSchedule $schedule) {
                if (!$schedule->site || !$schedule->reportTemplate) {
                    return;
                }

                $period = $schedule->period ?? 'last_30_days';
                [$periodStart, $periodEnd] = match ($period) {
                    'last_7_days' => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
                    'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                    default => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
                };

                GenerateReport::dispatch(
                    $schedule->site,
                    $schedule->reportTemplate,
                    $periodStart,
                    $periodEnd,
                    'scheduled',
                    $schedule,
                );
            });
    }
}
