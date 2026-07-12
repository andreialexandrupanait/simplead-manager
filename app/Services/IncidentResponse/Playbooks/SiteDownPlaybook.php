<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Playbooks;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\IncidentActionExecutor;
use Illuminate\Support\Facades\Log;

class SiteDownPlaybook implements PlaybookInterface
{
    public function name(): string
    {
        return 'site_down';
    }

    public function matches(IncidentTriggerType $trigger, array $context): bool
    {
        return $trigger === IncidentTriggerType::SiteDown;
    }

    public function allowedActions(): array
    {
        return ['deactivate_plugin', 'fix_elementor'];
    }

    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool
    {
        // Step 1: Quick recheck — maybe it recovered already
        $check = $executor->execute($response, $site, 'check_site_up', 'playbook');
        if ($check['is_up'] ?? false) {
            $response->update(['diagnosis' => ['result' => 'Site recovered on its own before intervention']]);

            return true;
        }

        // Step 2: Run diagnostic via connector (if site connector is still reachable)
        if ($site->is_connected) {
            $diagnostic = $executor->execute($response, $site, 'run_diagnostic', 'playbook');
            $response->update(['diagnosis' => $diagnostic]);

            // Step 3: Analyze diagnostic — look for fatal error pointing to a plugin
            if ($this->tryFixFatalPlugin($response, $site, $executor, $diagnostic)) {
                return true;
            }

            // Step 4: Try Elementor fix if Elementor-related errors detected
            if ($this->isElementorRelated($diagnostic)) {
                $executor->execute($response, $site, 'fix_elementor', 'playbook');
                $recheck = $executor->execute($response, $site, 'check_site_up', 'playbook');
                if ($recheck['is_up'] ?? false) {
                    return true;
                }
            }
        }

        // Step 5: Flush caches — resolves stale cache issues
        if ($site->is_connected) {
            $executor->execute($response, $site, 'flush_cache', 'playbook');
            $recheck = $executor->execute($response, $site, 'check_site_up', 'playbook');
            if ($recheck['is_up'] ?? false) {
                return true;
            }
        }

        // Playbook could not resolve — let AI take over
        Log::info("SiteDownPlaybook could not resolve site {$site->id}, deferring to AI");

        return false;
    }

    private function tryFixFatalPlugin(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $diagnostic): bool
    {
        $fatalError = $diagnostic['fatal_error'] ?? $diagnostic['php_fatal'] ?? null;
        if (! $fatalError) {
            return false;
        }

        $fatalMessage = is_array($fatalError) ? ($fatalError['message'] ?? '') : (string) $fatalError;
        $fatalFile = is_array($fatalError) ? ($fatalError['file'] ?? '') : '';

        // Try to find the plugin that caused the fatal error
        $culpritPlugin = $this->findCulpritPlugin($site, $fatalMessage, $fatalFile);
        if (! $culpritPlugin) {
            return false;
        }

        // Deactivate the culprit plugin
        $result = $executor->execute($response, $site, 'deactivate_plugin', 'playbook', [
            'plugin_id' => $culpritPlugin->id,
            'reason' => "Fatal error detected: {$fatalMessage}",
        ]);

        if (! ($result['success'] ?? false)) {
            return false;
        }

        // Check if site is back up
        $recheck = $executor->execute($response, $site, 'check_site_up', 'playbook');

        return $recheck['is_up'] ?? false;
    }

    private function findCulpritPlugin(Site $site, string $errorMessage, string $errorFile): ?\App\Models\SitePlugin
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\SitePlugin> $plugins */
        $plugins = $site->sitePlugins()->where('is_active', true)->get();

        foreach ($plugins as $plugin) {
            $pluginDir = dirname($plugin->file);
            if ($pluginDir && (str_contains($errorFile, $pluginDir) || str_contains($errorMessage, $pluginDir))) {
                return $plugin;
            }
        }

        return null;
    }

    private function isElementorRelated(array $diagnostic): bool
    {
        $json = json_encode($diagnostic);

        return str_contains(strtolower($json), 'elementor');
    }
}
