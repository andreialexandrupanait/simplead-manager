<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Contracts;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\IncidentActionExecutor;

interface PlaybookInterface
{
    public function name(): string;

    public function matches(IncidentTriggerType $trigger, array $context): bool;

    /**
     * P1-46: the mutating executor actions this playbook is permitted to run.
     * When this playbook is the triggering one, the incident restricts the AI
     * agent (and playbook) to this allowlist; mutating actions outside it are
     * refused/escalated. Read-only/diagnostic actions are never gated.
     *
     * @return list<string>
     */
    public function allowedActions(): array;

    /**
     * Execute the playbook steps. Return true if the issue was resolved.
     */
    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool;
}
