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
     * Execute the playbook steps. Return true if the issue was resolved.
     */
    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool;
}
