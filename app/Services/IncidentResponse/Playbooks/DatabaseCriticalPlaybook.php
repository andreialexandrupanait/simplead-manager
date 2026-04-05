<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Playbooks;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\IncidentActionExecutor;

class DatabaseCriticalPlaybook implements PlaybookInterface
{
    public function name(): string
    {
        return 'database_critical';
    }

    public function matches(IncidentTriggerType $trigger, array $context): bool
    {
        return $trigger === IncidentTriggerType::DatabaseCritical;
    }

    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool
    {
        $response->update(['diagnosis' => ['trigger' => 'Database health critical']]);

        // Database cleanup (revisions, transients, spam, orphaned meta)
        $result = $executor->execute($response, $site, 'db_cleanup', 'playbook');

        // Verify via health check
        $health = $executor->execute($response, $site, 'health_check', 'playbook');

        return ($health['status'] ?? '') === 'ok';
    }
}
