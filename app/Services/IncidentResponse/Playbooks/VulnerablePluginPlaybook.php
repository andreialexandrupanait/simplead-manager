<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Playbooks;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Models\VulnerabilityAlert;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\IncidentActionExecutor;

class VulnerablePluginPlaybook implements PlaybookInterface
{
    public function name(): string
    {
        return 'vulnerable_plugin';
    }

    public function matches(IncidentTriggerType $trigger, array $context): bool
    {
        return $trigger === IncidentTriggerType::Vulnerability;
    }

    public function allowedActions(): array
    {
        return ['update_plugin'];
    }

    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool
    {
        $alertId = $context['vulnerability_alert_id'] ?? null;
        if (! $alertId) {
            return false;
        }

        /** @var VulnerabilityAlert|null $alert */
        $alert = VulnerabilityAlert::find($alertId);
        if (! $alert || ! $alert->fixed_in_version) {
            return false;
        }

        // Find the plugin that needs updating
        /** @var \App\Models\SitePlugin|null $plugin */
        $plugin = $site->sitePlugins()
            ->where('slug', $alert->software_slug)
            ->where('has_update', true)
            ->first();

        if (! $plugin) {
            $response->update(['diagnosis' => ['result' => 'Plugin not found or no update available']]);

            return false;
        }

        $response->update(['diagnosis' => [
            'vulnerability' => $alert->title,
            'severity' => $alert->severity,
            'plugin' => $plugin->name,
            'installed_version' => $alert->installed_version,
            'fixed_in_version' => $alert->fixed_in_version,
        ]]);

        // Update the plugin via safe update (includes backup + health check)
        $result = $executor->execute($response, $site, 'update_plugin', 'playbook', [
            'plugin_id' => $plugin->id,
        ]);

        if ($result['success'] ?? false) {
            // Verify site is still healthy
            $health = $executor->execute($response, $site, 'health_check', 'playbook');

            return ($health['status'] ?? '') === 'ok';
        }

        return false;
    }
}
