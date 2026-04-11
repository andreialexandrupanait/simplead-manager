<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Jobs\CheckDns;
use App\Jobs\CheckUptime;
use App\Jobs\RunSecurityScan;
use App\Models\DnsMonitor;
use App\Models\SecurityMonitor;
use App\Models\UptimeMonitor;
use App\Services\CircuitBreakerService;

class MonitoringDispatcher
{
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
    }

    private function dispatchUptimeChecks(): void
    {
        UptimeMonitor::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
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
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
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
