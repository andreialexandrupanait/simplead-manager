<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\Playbooks\DatabaseCriticalPlaybook;
use App\Services\IncidentResponse\Playbooks\PerformanceDropPlaybook;
use App\Services\IncidentResponse\Playbooks\SecurityCriticalPlaybook;
use App\Services\IncidentResponse\Playbooks\SiteDownPlaybook;
use App\Services\IncidentResponse\Playbooks\VulnerablePluginPlaybook;

class PlaybookRunner
{
    /** @var PlaybookInterface[] */
    private array $playbooks;

    public function __construct()
    {
        $this->playbooks = [
            new SiteDownPlaybook,
            new VulnerablePluginPlaybook,
            new SecurityCriticalPlaybook,
            new PerformanceDropPlaybook,
            new DatabaseCriticalPlaybook,
        ];
    }

    public function findPlaybook(IncidentTriggerType $trigger, array $context): ?PlaybookInterface
    {
        foreach ($this->playbooks as $playbook) {
            if ($playbook->matches($trigger, $context)) {
                return $playbook;
            }
        }

        return null;
    }

    public function run(
        IncidentResponse $response,
        Site $site,
        IncidentTriggerType $trigger,
        IncidentActionExecutor $executor,
        array $context,
    ): bool {
        $playbook = $this->findPlaybook($trigger, $context);

        if (! $playbook) {
            return false;
        }

        $response->update(['playbook_name' => $playbook->name()]);

        return $playbook->execute($response, $site, $executor, $context);
    }
}
