<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Jobs\CheckDns;
use App\Jobs\CheckUptime;
use App\Jobs\RunPerformanceTest;
use App\Jobs\RunSecurityScan;
use App\Models\DnsMonitor;
use App\Models\PerformanceMonitor;
use App\Models\SecurityMonitor;
use App\Models\UptimeMonitor;
use App\Services\CircuitBreakerService;
use Illuminate\Database\Eloquent\Builder;

class MonitoringDispatcher
{
    /**
     * LEFT-JOIN-safe health-state gate (E-28 / P1-10). Includes sites whose
     * circuit breaker is not open and whose monitoring is not disabled — AND
     * sites that have NO health-state row at all. The previous inner-join
     * `whereHas('site.healthState')` silently dropped any site lacking that row,
     * leaving connected sites permanently unmonitored / unsynced.
     */
    private function healthStateGate(string $relation = 'site.healthState'): \Closure
    {
        return function (Builder $query) use ($relation) {
            $query->whereDoesntHave($relation)
                ->orWhereHas($relation, function (Builder $q) {
                    $q->where('circuit_state', '!=', 'open')
                        ->where('is_monitoring_disabled', false);
                });
        };
    }

    /**
     * Dispatch due uptime checks and security scans.
     * Called every minute from the scheduler.
     */
    public function __invoke(): void
    {
        CircuitBreakerService::checkHalfOpen();

        $this->dispatchUptimeChecks();
        $this->dispatchSecurityScans();
        $this->dispatchDnsChecks();
        $this->dispatchPerformanceTests();
    }

    /**
     * Scheduled PageSpeed tests. These never ran (PF-P1-1) — next_test_at was
     * written but nothing consumed it, so dashboards and client reports showed
     * only stale, manually-triggered scores. Staggered to stay within the PSI
     * API quota when many sites come due at once.
     */
    private function dispatchPerformanceTests(): void
    {
        $queued = 0;

        PerformanceMonitor::query()
            ->where('is_active', true)
            // Only auto-run monitors on a real recurring cadence. 'manual' (and
            // any other value) leaves next_test_at null forever, which the due
            // check below would otherwise treat as "due" every single minute —
            // a PSI test loop that burns the API quota. Manual monitors run only
            // when triggered from the UI.
            ->whereIn('frequency', ['daily', 'weekly'])
            ->where(fn ($q) => $q->whereNull('next_test_at')->orWhere('next_test_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->where($this->healthStateGate())
            ->each(function (PerformanceMonitor $monitor) use (&$queued) {
                RunPerformanceTest::dispatch($monitor, 'both')
                    ->delay(now()->addSeconds($queued * 20));
                $queued++;
            });
    }

    private function dispatchUptimeChecks(): void
    {
        UptimeMonitor::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->where($this->healthStateGate())
            ->each(fn (UptimeMonitor $monitor) => CheckUptime::dispatch($monitor));
    }

    private function dispatchSecurityScans(): void
    {
        SecurityMonitor::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_scan_at')->orWhere('next_scan_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->where($this->healthStateGate())
            ->each(function (SecurityMonitor $monitor) {
                /** @var \App\Models\Site $site */
                $site = $monitor->site;
                RunSecurityScan::dispatch($site);
            });
    }

    private function dispatchDnsChecks(): void
    {
        DnsMonitor::query()
            ->active()
            ->due()
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->each(fn (DnsMonitor $monitor) => CheckDns::dispatch($monitor));
    }
}
