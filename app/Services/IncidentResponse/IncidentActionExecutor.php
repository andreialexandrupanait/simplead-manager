<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\CreateBackup;
use App\Models\IncidentResponse;
use App\Models\IncidentResponseAction;
use App\Models\RollbackPoint;
use App\Models\Site;
use App\Services\DatabaseCleanupService;
use App\Services\PluginManagerService;
use App\Services\SafeUpdateService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IncidentActionExecutor
{
    private int $sequence = 0;

    public function __construct(
        private readonly WordPressApiServiceFactory $apiFactory,
        private readonly PluginManagerService $pluginManager,
        private readonly SafeUpdateService $safeUpdateService,
        private readonly DatabaseCleanupService $dbCleanupService,
    ) {}

    public function execute(
        IncidentResponse $response,
        Site $site,
        string $actionType,
        string $tier,
        array $parameters = [],
    ): array {
        if ($response->hasReachedActionLimit()) {
            return ['success' => false, 'error' => 'Action limit reached for this incident'];
        }

        $this->ensureBackupIfDestructive($response, $site, $actionType);

        $startTime = microtime(true);
        $result = [];
        $status = 'success';
        $errorMessage = null;

        try {
            $result = match ($actionType) {
                'run_diagnostic' => $this->runDiagnostic($site),
                'health_check' => $this->healthCheck($site),
                'check_site_up' => $this->checkSiteUp($site),
                'flush_cache' => $this->flushCache($site),
                'deactivate_plugin' => $this->deactivatePlugin($site, $parameters),
                'activate_plugin' => $this->activatePlugin($site, $parameters),
                'update_plugin' => $this->updatePlugin($site, $response, $parameters),
                'rollback_plugin' => $this->rollbackPlugin($parameters),
                'create_backup' => $this->createBackup($site, $response),
                'db_cleanup' => $this->dbCleanup($site),
                'apply_security_fix' => $this->applySecurityFix($site, $parameters),
                'fix_elementor' => $this->fixElementor($site),
                'get_server_resources' => $this->getServerResources($site),
                default => ['success' => false, 'error' => "Unknown action: {$actionType}"],
            };
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            $result = ['success' => false, 'error' => $e->getMessage()];
            Log::warning("Incident action {$actionType} failed for site {$site->id}: {$e->getMessage()}");
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        IncidentResponseAction::create([
            'incident_response_id' => $response->id,
            'action_type' => $actionType,
            'tier' => $tier,
            'parameters' => $parameters ?: null,
            'result' => $result,
            'status' => $status,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'sequence' => $this->sequence++,
        ]);

        $response->incrementActionsCount();

        return $result;
    }

    private function ensureBackupIfDestructive(IncidentResponse $response, Site $site, string $actionType): void
    {
        if (! $response->backup_created
            && config('incident-response.safety.always_backup_before_destructive', true)
            && in_array($actionType, config('incident-response.safety.destructive_actions', []))
        ) {
            $this->createBackup($site, $response);
        }
    }

    private function api(Site $site): WordPressApiServiceInterface
    {
        return $this->apiFactory->make($site);
    }

    private function runDiagnostic(Site $site): array
    {
        return $this->api($site)->runDiagnostic();
    }

    private function healthCheck(Site $site): array
    {
        return $this->api($site)->healthCheck();
    }

    private function checkSiteUp(Site $site): array
    {
        try {
            $start = microtime(true);
            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'SimpleAdIncidentResponder/1.0'])
                ->get($site->url);

            $responseTime = (int) round((microtime(true) - $start) * 1000);

            return [
                'is_up' => $response->successful(),
                'status_code' => $response->status(),
                'response_time_ms' => $responseTime,
            ];
        } catch (\Throwable $e) {
            return [
                'is_up' => false,
                'status_code' => null,
                'response_time_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function flushCache(Site $site): array
    {
        return $this->api($site)->clearCache();
    }

    private function deactivatePlugin(Site $site, array $parameters): array
    {
        $pluginId = $parameters['plugin_id'] ?? null;
        if (! $pluginId) {
            return ['success' => false, 'error' => 'Missing plugin_id'];
        }

        return $this->pluginManager->deactivatePlugin($site, (int) $pluginId);
    }

    private function activatePlugin(Site $site, array $parameters): array
    {
        $pluginId = $parameters['plugin_id'] ?? null;
        if (! $pluginId) {
            return ['success' => false, 'error' => 'Missing plugin_id'];
        }

        return $this->pluginManager->activatePlugin($site, (int) $pluginId);
    }

    private function updatePlugin(Site $site, IncidentResponse $response, array $parameters): array
    {
        $pluginId = $parameters['plugin_id'] ?? null;
        if (! $pluginId) {
            return ['success' => false, 'error' => 'Missing plugin_id'];
        }

        /** @var \App\Models\SitePlugin|null $plugin */
        $plugin = $site->sitePlugins()->find((int) $pluginId);
        if (! $plugin) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if (! $plugin->has_update) {
            return ['success' => false, 'error' => 'No update available'];
        }

        $safeUpdate = $this->safeUpdateService->createSafeUpdate(
            $site, 'plugin', $plugin->slug, $plugin->name,
            $plugin->version, $plugin->update_version,
        );
        $this->safeUpdateService->runSafeUpdate($safeUpdate);

        return [
            'success' => $safeUpdate->fresh()->status === 'completed',
            'from_version' => $plugin->version,
            'to_version' => $plugin->update_version,
        ];
    }

    private function rollbackPlugin(array $parameters): array
    {
        $rollbackPointId = $parameters['rollback_point_id'] ?? null;
        if (! $rollbackPointId) {
            return ['success' => false, 'error' => 'Missing rollback_point_id'];
        }

        $point = RollbackPoint::find((int) $rollbackPointId);
        if (! $point) {
            return ['success' => false, 'error' => 'Rollback point not found'];
        }

        $rollbackService = app(\App\Services\RollbackService::class);

        return $rollbackService->executeRollback($point);
    }

    private function createBackup(Site $site, IncidentResponse $response): array
    {
        try {
            $config = $site->backupConfig;
            if ($config) {
                CreateBackup::dispatchSync($site, 'database', 'incident_response', $config->storage_destination_id);
            }
            $response->update(['backup_created' => true]);

            return ['success' => true, 'message' => 'Database backup created'];
        } catch (\Throwable $e) {
            Log::warning("Incident response backup failed for site {$site->id}: {$e->getMessage()}");

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function dbCleanup(Site $site): array
    {
        $cleanup = $this->dbCleanupService->run($site, [
            'revisions' => true,
            'auto_drafts' => true,
            'trashed_posts' => true,
            'spam_comments' => true,
            'trashed_comments' => true,
            'expired_transients' => true,
            'orphaned_postmeta' => true,
            'orphaned_commentmeta' => true,
            'orphaned_usermeta' => true,
            'orphaned_termmeta' => true,
        ]);

        return [
            'success' => $cleanup->status === 'completed',
            'total_deleted' => $cleanup->total_deleted ?? 0,
            'space_saved' => $cleanup->space_saved ?? 0,
        ];
    }

    private function applySecurityFix(Site $site, array $parameters): array
    {
        $key = $parameters['key'] ?? null;
        if (! $key) {
            return ['success' => false, 'error' => 'Missing security fix key'];
        }

        return $this->api($site)->applySecurityFix($key);
    }

    private function fixElementor(Site $site): array
    {
        return $this->api($site)->fixElementor();
    }

    private function getServerResources(Site $site): array
    {
        return $this->api($site)->getServerResources();
    }
}
