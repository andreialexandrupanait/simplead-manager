<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Models\Backup;
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

    /**
     * P1-46: the mutating actions this incident is permitted to perform, derived
     * from the triggering playbook. When null, the conservative config default is
     * used. Mutating actions outside this list are refused/escalated rather than
     * executed on raw AI whim. Read-only/diagnostic actions are never gated.
     *
     * @var list<string>|null
     */
    private ?array $allowedMutatingActions = null;

    public function __construct(
        private readonly WordPressApiServiceFactory $apiFactory,
        private readonly PluginManagerService $pluginManager,
        private readonly SafeUpdateService $safeUpdateService,
        private readonly DatabaseCleanupService $dbCleanupService,
    ) {}

    /**
     * Constrain which mutating actions this incident may run (P1-46). Pass the
     * playbook's allowlist; null restores the conservative config default.
     *
     * @param  list<string>|null  $actions
     */
    public function setAllowedActions(?array $actions): void
    {
        $this->allowedMutatingActions = $actions;
    }

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

        // P1-46: a mutating action may only run when the triggering incident's
        // playbook allowlist permits it. Malicious/attacker-controlled site
        // content fed to the AI agent must not be able to steer it into an
        // out-of-scope state change — refuse (escalate to a human) instead.
        if (! $this->isActionAllowed($actionType)) {
            $error = "Refused '{$actionType}': not permitted by this incident's playbook allowlist — "
                .'escalating instead of running an out-of-scope mutating action.';
            Log::warning("Incident action {$actionType} refused for site {$site->id}: outside playbook allowlist");

            $this->recordAction($response, $actionType, $tier, $parameters, [
                'success' => false,
                'error' => $error,
            ], 'refused', $error, 0);

            return ['success' => false, 'error' => $error];
        }

        // P0-20: a destructive action may only proceed when a completed, verified
        // backup actually exists. If none can be created (e.g. the site has no
        // backup capability configured, or the backup did not complete), REFUSE the
        // action rather than mutate a live site believing in a backup that isn't
        // there — the safe posture is to escalate to a human.
        if (! $this->ensureBackupIfDestructive($response, $site, $actionType)) {
            $error = "Refused '{$actionType}': no verified backup exists for this site — "
                .'escalating instead of running a destructive action without a recovery point.';
            Log::warning("Incident action {$actionType} refused for site {$site->id}: backup invariant not satisfied");

            $this->recordAction($response, $actionType, $tier, $parameters, [
                'success' => false,
                'error' => $error,
            ], 'refused', $error, 0);

            return ['success' => false, 'error' => $error];
        }

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
                'rollback_plugin' => $this->rollbackPlugin($site, $parameters),
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

        $this->recordAction($response, $actionType, $tier, $parameters, $result, $status, $errorMessage, $durationMs);

        return $result;
    }

    private function recordAction(
        IncidentResponse $response,
        string $actionType,
        string $tier,
        array $parameters,
        array $result,
        string $status,
        ?string $errorMessage,
        int $durationMs,
    ): void {
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
    }

    /**
     * P0-20: guarantee a real recovery point before a destructive action.
     * Returns true only when it is safe to proceed — i.e. a verified backup
     * already exists for this incident, or one was just completed and verified.
     * Returns false when the invariant cannot be satisfied so the caller refuses.
     */
    private function ensureBackupIfDestructive(IncidentResponse $response, Site $site, string $actionType): bool
    {
        if (! config('incident-response.safety.always_backup_before_destructive', true)) {
            return true;
        }

        if (! in_array($actionType, config('incident-response.safety.destructive_actions', []), true)) {
            return true;
        }

        if ($response->backup_created) {
            return true;
        }

        return $this->createBackup($site, $response)['success'] === true;
    }

    /**
     * P1-46: read-only/diagnostic actions are always allowed; mutating actions
     * must appear in this incident's playbook allowlist (or the conservative
     * config default when no playbook set one).
     */
    private function isActionAllowed(string $actionType): bool
    {
        if (! in_array($actionType, (array) config('incident-response.safety.mutating_actions', []), true)) {
            return true;
        }

        $allowed = $this->allowedMutatingActions
            ?? (array) config('incident-response.safety.default_allowed_actions', []);

        return in_array($actionType, $allowed, true);
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
        // P1-45: the per-site safe-updates opt-in gates automated plugin updates.
        // A site that opted OUT must not have its plugins updated by a
        // vulnerability/incident-driven action just because it bypasses the manual
        // UI path — refuse and let the incident escalate to a human instead.
        if (! $site->safe_updates_enabled) {
            Log::warning("Incident update_plugin refused for site {$site->id}: safe_updates_enabled is off");

            return [
                'success' => false,
                'error' => 'Site has not opted into automated safe updates (safe_updates_enabled=false); '
                    .'refusing incident-driven plugin update — escalate for manual handling.',
            ];
        }

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
            // The connector updates plugins by their file, not their slug.
            $plugin->file,
        );
        $this->safeUpdateService->runSafeUpdate($safeUpdate);

        return [
            'success' => $safeUpdate->fresh()->status === 'completed',
            'from_version' => $plugin->version,
            'to_version' => $plugin->update_version,
        ];
    }

    private function rollbackPlugin(Site $site, array $parameters): array
    {
        $rollbackPointId = $parameters['rollback_point_id'] ?? null;
        if (! $rollbackPointId) {
            return ['success' => false, 'error' => 'Missing rollback_point_id'];
        }

        // The id comes from the AI tool call — scope it to the incident's own
        // site so a hallucinated/wrong id can never downgrade another tenant's
        // plugin, and require an available point (not already used/expired).
        $point = RollbackPoint::where('site_id', $site->id)
            ->where('id', (int) $rollbackPointId)
            ->first();
        if (! $point) {
            return ['success' => false, 'error' => 'Rollback point not found for this site'];
        }

        if ($point->status !== 'available') {
            return ['success' => false, 'error' => "Rollback point is not available (status: {$point->status})"];
        }

        $rollbackService = app(\App\Services\RollbackService::class);

        return $rollbackService->executeRollback($point);
    }

    private function createBackup(Site $site, IncidentResponse $response): array
    {
        // No backup capability configured — we cannot create a recovery point, so
        // never claim one exists. The caller refuses/escalates the destructive work.
        $config = $site->backupConfig;
        if (! $config) {
            Log::warning("Incident response: site {$site->id} has no backup configuration — cannot create a pre-action backup");

            return ['success' => false, 'error' => 'No backup configuration for this site; cannot create a backup'];
        }

        try {
            CreateBackup::dispatchSync($site, 'database', 'incident_response', $config->storage_destination_id);
        } catch (\Throwable $e) {
            Log::warning("Incident response backup failed for site {$site->id}: {$e->getMessage()}");

            return ['success' => false, 'error' => $e->getMessage()];
        }

        // The CreateBackup job returns silently when it can't acquire the site lock
        // or drops a duplicate dispatch — so success is confirmed ONLY by a real
        // completed + verification-passed Backup row, never by the dispatch itself.
        $backup = $this->findVerifiedBackup($site, $response);
        if (! $backup) {
            Log::warning("Incident response: backup dispatch for site {$site->id} did not yield a completed, verified backup");

            return ['success' => false, 'error' => 'Backup did not complete or failed verification'];
        }

        $response->update(['backup_created' => true, 'backup_id' => $backup->id]);

        return [
            'success' => true,
            'message' => 'Database backup created and verified',
            'backup_id' => $backup->id,
        ];
    }

    /**
     * A backup satisfies the pre-destructive-action invariant only if it is a
     * completed + verification-passed backup for this site that is at least as
     * fresh as the incident itself (a stale week-old backup does not count as a
     * recovery point for the mutation we are about to perform).
     */
    private function findVerifiedBackup(Site $site, IncidentResponse $response): ?Backup
    {
        $since = $response->created_at ?? now();

        return Backup::query()
            ->where('site_id', $site->id)
            ->where('status', BackupStatus::Completed)
            ->where('verification_status', 'passed')
            ->where('completed_at', '>=', $since)
            ->orderByDesc('id')
            ->first();
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
