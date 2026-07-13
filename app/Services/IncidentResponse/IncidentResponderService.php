<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Exceptions\IncidentSkippedException;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class IncidentResponderService
{
    public function __construct(
        private readonly IncidentActionExecutor $executor,
        private readonly PlaybookRunner $playbookRunner,
        private readonly ContextGatherer $contextGatherer,
        private readonly AiAgentService $aiAgent,
    ) {}

    public function respond(
        Site $site,
        IncidentTriggerType $trigger,
        string $triggerSource,
        ?int $triggerSourceId = null,
        array $context = [],
    ): IncidentResponse {
        // P3-32: these three are intentional guardrail SKIPS, not failures. They
        // throw a typed IncidentSkippedException (a RuntimeException subtype, so
        // the message contract is unchanged) that the job catches specifically and
        // logs at debug — a routine cooldown skip no longer pollutes the error log.
        if (! config('incident-response.enabled', false)) {
            throw new IncidentSkippedException('Incident response is disabled');
        }

        // Safety: cooldown check
        if ($this->isInCooldown($site, $trigger)) {
            Log::debug("Incident response skipped for site {$site->id}: cooldown active for {$trigger->value}");
            throw new IncidentSkippedException('Cooldown active');
        }

        // Safety: rate limit per site
        if ($this->hasExceededHourlyLimit($site)) {
            Log::debug("Incident response skipped for site {$site->id}: hourly limit exceeded");
            throw new IncidentSkippedException('Hourly limit exceeded');
        }

        $response = IncidentResponse::create([
            'site_id' => $site->id,
            'trigger_type' => $trigger,
            'trigger_source' => $triggerSource,
            'trigger_source_id' => $triggerSourceId,
            'status' => IncidentResponseStatus::Pending,
            // P0-21: records when the pipeline last ran for this (site, trigger) so
            // the dispatcher can apply cooldown/backoff off a real attempt timestamp.
            'response_attempted_at' => now(),
        ]);

        ActivityLogger::log(
            type: 'incident_response',
            severity: 'warning',
            title: "Incident response started: {$trigger->label()}",
            description: "Investigating {$trigger->label()} on {$site->name}",
            site: $site,
            icon: 'shield-alert',
        );

        try {
            $this->runResponse($response, $site, $trigger, $context);
        } catch (\Throwable $e) {
            Log::error("Incident response failed for site {$site->id}: {$e->getMessage()}");
            if (! $response->status->isTerminal()) {
                $response->markFailed("Unhandled error: {$e->getMessage()}");
            }
        }

        $this->sendNotification($response, $site);

        return $response->fresh();
    }

    private function runResponse(
        IncidentResponse $response,
        Site $site,
        IncidentTriggerType $trigger,
        array $context,
    ): void {
        $response->markDiagnosing();

        // P1-46: constrain every executor mutating action for this incident to the
        // triggering playbook's allowlist (or the conservative default when no
        // playbook matches). This bounds the AI agent — attacker-controlled site
        // content can't steer it into an out-of-scope state change.
        $this->executor->setAllowedActions(
            $this->playbookRunner->allowedActionsFor($trigger, $context),
        );

        // Tier 1: Playbook
        if (config('incident-response.routing.playbook_first', true)) {
            $resolved = $this->playbookRunner->run($response, $site, $trigger, $this->executor, $context);

            if ($resolved) {
                $response->markResolved(
                    "Resolved by playbook: {$response->playbook_name}",
                    'playbook',
                );

                return;
            }
        }

        // Tier 2: AI Agent
        if (config('incident-response.routing.ai_fallback', true) && config('incident-response.ai.api_key')) {
            $response->markExecuting();

            $aiContext = $this->contextGatherer->gather($site, $context);
            $resolved = $this->aiAgent->diagnoseAndFix($response, $site, $this->executor, $aiContext);

            if ($resolved) {
                return; // AI agent sets status via resolve_incident/escalate_to_human tools
            }
        }

        // Tier 3: Escalate to human
        if (! $response->status->isTerminal()) {
            $response->markEscalated('Could not resolve automatically. Playbook and AI agent both failed.');
        }
    }

    private function isInCooldown(Site $site, IncidentTriggerType $trigger): bool
    {
        $cooldownMinutes = config('incident-response.safety.cooldown_minutes', 30);

        return IncidentResponse::where('site_id', $site->id)
            ->where('trigger_type', $trigger)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();
    }

    private function hasExceededHourlyLimit(Site $site): bool
    {
        $limit = config('incident-response.safety.max_incidents_per_site_per_hour', 3);

        return IncidentResponse::where('site_id', $site->id)
            ->where('created_at', '>=', now()->subHour())
            ->count() >= $limit;
    }

    private function sendNotification(IncidentResponse $response, Site $site): void
    {
        $event = match ($response->status) {
            IncidentResponseStatus::Resolved => 'incident_response_resolved',
            IncidentResponseStatus::Escalated => 'incident_response_escalated',
            IncidentResponseStatus::Failed => 'incident_response_failed',
            default => null,
        };

        if (! $event) {
            return;
        }

        $severity = match ($response->status) {
            IncidentResponseStatus::Resolved => 'success',
            default => 'critical',
        };

        NotificationService::notifySiteEvent(
            site: $site,
            event: $event,
            title: "Incident Response: {$response->status->label()}",
            message: $response->summary ?? "Incident response {$response->status->value} for {$site->name}",
            fields: [
                'Trigger' => $response->trigger_type->label(),
                'Method' => $response->resolution_method ?? 'N/A',
                'Actions' => (string) $response->actions_count,
            ],
            severity: $severity,
        );
    }
}
