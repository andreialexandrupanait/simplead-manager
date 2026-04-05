<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Enums\IncidentTriggerType;
use App\Jobs\RunIncidentResponse;
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
            ->whereDoesntHave('site.incidentResponses', fn ($q) => $q
                ->where('trigger_type', IncidentTriggerType::Vulnerability)
                ->where('created_at', '>=', now()->subMinutes(
                    config('incident-response.safety.cooldown_minutes', 30)
                ))
            )
            ->with('site')
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
            ->where('status', '!=', 'fixed')
            ->where('status', '!=', 'ignored')
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
            ->whereDoesntHave('site.incidentResponses', fn ($q) => $q
                ->where('trigger_type', IncidentTriggerType::SecurityCritical)
                ->where('created_at', '>=', now()->subMinutes(
                    config('incident-response.safety.cooldown_minutes', 30)
                ))
            )
            ->with('site')
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
