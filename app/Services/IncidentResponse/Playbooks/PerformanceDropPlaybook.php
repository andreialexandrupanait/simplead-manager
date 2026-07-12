<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Playbooks;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\IncidentActionExecutor;

class PerformanceDropPlaybook implements PlaybookInterface
{
    public function name(): string
    {
        return 'performance_drop';
    }

    public function matches(IncidentTriggerType $trigger, array $context): bool
    {
        return $trigger === IncidentTriggerType::PerformanceDrop;
    }

    public function allowedActions(): array
    {
        return ['db_cleanup'];
    }

    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool
    {
        $response->update(['diagnosis' => ['trigger' => 'Performance score below threshold']]);

        // Step 1: Flush all caches
        $executor->execute($response, $site, 'flush_cache', 'playbook');

        // Step 2: Database cleanup
        $executor->execute($response, $site, 'db_cleanup', 'playbook');

        // Step 3: Verify improvement via health check
        $health = $executor->execute($response, $site, 'health_check', 'playbook');

        return ($health['status'] ?? '') === 'ok';
    }
}
