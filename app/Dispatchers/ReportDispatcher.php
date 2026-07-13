<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Jobs\GenerateReport;
use App\Jobs\NotifyUpcomingReport;
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
        // Send 3-day reminder notifications for upcoming reports
        ReportSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '>', now())
            ->where('next_run_at', '<=', now()->addDays(3))
            ->where(fn ($q) => $q->whereNull('reminder_sent_at')->orWhere('reminder_sent_at', '<', now()->subDays(3)))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->with('site')
            ->each(function (ReportSchedule $schedule) {
                NotifyUpcomingReport::dispatch($schedule);
                $schedule->update(['reminder_sent_at' => now()]);
            });

        // Dispatch due report generation jobs
        ReportSchedule::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->with(['site', 'reportTemplate'])
            ->each(function (ReportSchedule $schedule) {
                if (! $schedule->site || ! $schedule->reportTemplate) {
                    return;
                }

                // Atomic claim: update next_run_at BEFORE dispatching.
                // WHERE condition ensures only one dispatcher tick wins per schedule.
                $claimed = ReportSchedule::where('id', $schedule->id)
                    ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
                    ->update([
                        'next_run_at' => $schedule->calculateNextRun(),
                        'reminder_sent_at' => null,
                    ]);

                if ($claimed === 0) {
                    return;
                }

                /** @var \App\Models\Site $site */
                $site = $schedule->site;
                /** @var \App\Models\ReportTemplate $template */
                $template = $schedule->reportTemplate;

                $period = $schedule->period ?? 'last_30_days';
                [$periodStart, $periodEnd] = match ($period) {
                    // P3-23: subDays(7)->startOfDay()..endOfDay() spanned 8 calendar
                    // days (today-7 through today). A true 7-day window is today-6
                    // through today inclusive.
                    'last_7_days' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
                    'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                    default => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
                };

                GenerateReport::dispatch(
                    $site,
                    $template,
                    $periodStart,
                    $periodEnd,
                    'scheduled',
                    $schedule,
                );
            });
    }
}
