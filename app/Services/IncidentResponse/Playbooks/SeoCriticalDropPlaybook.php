<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Playbooks;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\IncidentActionExecutor;

class SeoCriticalDropPlaybook implements PlaybookInterface
{
    public function name(): string
    {
        return 'seo_critical_drop';
    }

    public function matches(IncidentTriggerType $trigger, array $context): bool
    {
        return $trigger === IncidentTriggerType::SeoCriticalDrop;
    }

    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool
    {
        $response->update(['diagnosis' => [
            'trigger' => 'SEO critical drop detected',
            'details' => $context['details'] ?? 'Significant drop in search visibility or rankings',
        ]]);

        // Step 1: Run diagnostics to check for site-level issues
        $diagnostic = $executor->execute($response, $site, 'run_diagnostic', 'playbook');

        // Step 2: Health check — ensure the site is responding correctly
        $health = $executor->execute($response, $site, 'health_check', 'playbook');

        // Step 3: Flush caches — stale cache can serve wrong content/headers to crawlers
        $executor->execute($response, $site, 'flush_cache', 'playbook');

        // Step 4: Check if site is actually up (crawlers may have seen downtime)
        $siteUp = $executor->execute($response, $site, 'check_site_up', 'playbook');

        // Step 5: Clean database — bloated DB can slow site and affect crawlability
        $executor->execute($response, $site, 'db_cleanup', 'playbook');

        // The playbook addresses the most common technical causes of SEO drops:
        // - Site downtime (crawlers couldn't reach the site)
        // - Slow response times (cache cleared, DB cleaned)
        // - Technical errors (diagnostic check)
        // Manual investigation still needed for content/algorithmic changes.

        return ($siteUp['is_up'] ?? false) && ($health['status'] ?? '') === 'ok';
    }
}
