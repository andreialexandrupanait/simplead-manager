<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse;

use App\Models\Site;
use Illuminate\Support\Facades\Log;

class ContextGatherer
{
    public function gather(Site $site, array $triggerContext = []): array
    {
        $site->loadMissing([
            'healthState',
            'sitePlugins',
            'siteThemes',
        ]);

        $context = [
            'site' => $this->siteInfo($site),
            'trigger' => $triggerContext,
            'plugins' => $this->pluginInfo($site),
            'health_state' => $this->healthStateInfo($site),
            'recent_updates' => $this->recentUpdates($site),
            'security_issues' => $this->securityIssues($site),
            'vulnerabilities' => $this->vulnerabilities($site),
            'recent_activity' => $this->recentActivity($site),
        ];

        // Try to get live diagnostic if site is connected
        if ($site->is_connected) {
            $context['live_diagnostic'] = $this->liveDiagnostic($site);
        }

        return $context;
    }

    private function siteInfo(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'url' => $site->url,
            'wp_version' => $site->wp_version,
            'php_version' => $site->php_version,
            'is_connected' => $site->is_connected,
            'is_up' => $site->is_up,
            'uptime_percentage' => $site->uptime_percentage,
        ];
    }

    private function pluginInfo(Site $site): array
    {
        return $site->sitePlugins->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'version' => $p->version,
            'is_active' => $p->is_active,
            'has_update' => $p->has_update,
            'update_version' => $p->update_version,
        ])->toArray();
    }

    private function healthStateInfo(Site $site): ?array
    {
        $state = $site->healthState;
        if (! $state) {
            return null;
        }

        return [
            'circuit_state' => $state->circuit_state,
            'consecutive_failures' => $state->consecutive_failures,
            'is_monitoring_disabled' => $state->is_monitoring_disabled,
            'last_failure_reason' => $state->last_failure_reason,
        ];
    }

    private function recentUpdates(Site $site): array
    {
        return $site->updateLogs()
            ->latest()
            ->limit(10)
            ->get(['type', 'name', 'slug', 'from_version', 'to_version', 'success', 'error_message', 'performed_at'])
            ->toArray();
    }

    private function securityIssues(Site $site): array
    {
        return $site->securityIssues()
            ->whereIn('severity', ['critical', 'high'])
            ->where('status', '!=', 'fixed')
            ->get(['id', 'type', 'severity', 'title', 'description', 'status'])
            ->toArray();
    }

    private function vulnerabilities(Site $site): array
    {
        return $site->vulnerabilityAlerts()
            ->where('status', 'active')
            ->get(['id', 'plugin_slug', 'title', 'severity', 'cvss_score', 'installed_version', 'fixed_in_version'])
            ->toArray();
    }

    private function recentActivity(Site $site): array
    {
        return $site->activityLogs()
            ->latest()
            ->limit(15)
            ->get(['type', 'severity', 'title', 'description', 'created_at'])
            ->toArray();
    }

    private function liveDiagnostic(Site $site): array
    {
        try {
            $api = app(\App\Services\WordPressApiServiceFactory::class)->make($site);

            return $api->runDiagnostic();
        } catch (\Throwable $e) {
            Log::debug("Could not fetch live diagnostic for site {$site->id}: {$e->getMessage()}");

            return ['error' => 'Could not reach site for live diagnostic'];
        }
    }
}
