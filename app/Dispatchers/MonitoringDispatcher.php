<?php

namespace App\Dispatchers;

use App\Jobs\CheckSslCertificate;
use App\Jobs\CheckUptime;
use App\Jobs\RunSecurityScan;
use App\Models\SecurityMonitor;
use App\Models\SslCertificate;
use App\Models\UptimeMonitor;
use App\Services\CircuitBreakerService;

class MonitoringDispatcher
{
    /**
     * Dispatch due uptime checks, SSL checks, and security scans.
     * Called every minute from the scheduler.
     */
    public function __invoke(): void
    {
        CircuitBreakerService::checkHalfOpen();

        $this->dispatchUptimeChecks();
        $this->dispatchSslChecks();
        $this->dispatchSecurityScans();
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

    private function dispatchSslChecks(): void
    {
        SslCertificate::query()
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->each(fn (SslCertificate $cert) => CheckSslCertificate::dispatch($cert));
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
            ->each(fn (SecurityMonitor $monitor) => RunSecurityScan::dispatch($monitor->site));
    }
}
