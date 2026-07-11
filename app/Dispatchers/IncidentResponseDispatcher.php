<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Enums\IncidentTriggerType;
use App\Jobs\RunIncidentResponse;
use App\Models\IncidentResponse;
use App\Models\SecurityIssue;
use App\Models\VulnerabilityAlert;
use App\Services\CircuitBreakerService;

class IncidentResponseDispatcher
{
    /**
     * Proactively detect unaddressed security, vulnerability, performance,
     * and database issues. Called every 5 minutes from the scheduler.
     */
    public function __invoke(): void
    {
        if (! config('incident-response.enabled', false)) {
            return;
        }

        CircuitBreakerService::checkHalfOpen();

        $this->dispatchVulnerabilityResponses();
        $this->dispatchSecurityCriticalResponses();
    }

    private function dispatchVulnerabilityResponses(): void
    {
        VulnerabilityAlert::query()
            ->where('status', 'active')
            ->whereNotNull('fixed_in_version')
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
            ->with('site')
            ->get()
            // P0-21: suppress re-dispatch when the latest incident for this
            // (site, trigger) is still running, escalated-and-unacknowledged, in
            // cooldown, or inside an exponential failure backoff — otherwise a
            // stuck incident re-runs the whole AI pipeline every tick forever.
            ->reject(fn (VulnerabilityAlert $alert) => IncidentResponse::isRedispatchSuppressed(
                $alert->site_id,
                IncidentTriggerType::Vulnerability,
            ))
            ->each(function (VulnerabilityAlert $alert) {
                RunIncidentResponse::dispatch(
                    $alert->site,
                    IncidentTriggerType::Vulnerability,
                    'VulnerabilityAlert',
                    $alert->id,
                    ['vulnerability_alert_id' => $alert->id],
                );
            });
    }

    private function dispatchSecurityCriticalResponses(): void
    {
        SecurityIssue::query()
            ->whereIn('severity', ['critical', 'high'])
            // security_issues has no status column — open issues are tracked
            // via is_fixed/is_ignored (audit SEC-A2-04: the old status WHERE
            // threw SQLSTATE 42703 and killed the whole dispatch path)
            ->active()
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
            ->with('site')
            ->get()
            // P0-21: see dispatchVulnerabilityResponses — suppress re-dispatch of a
            // still-active/escalated/backing-off incident for this (site, trigger).
            ->reject(fn (SecurityIssue $issue) => IncidentResponse::isRedispatchSuppressed(
                $issue->site_id,
                IncidentTriggerType::SecurityCritical,
            ))
            ->each(function (SecurityIssue $issue) {
                RunIncidentResponse::dispatch(
                    $issue->site,
                    IncidentTriggerType::SecurityCritical,
                    'SecurityIssue',
                    $issue->id,
                    ['security_issue_id' => $issue->id],
                );
            });
    }
}
