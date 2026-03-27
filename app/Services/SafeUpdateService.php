<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CreateBackup;
use App\Models\SafeUpdate;
use App\Models\UpdateLog;
use Illuminate\Support\Facades\Log;

class SafeUpdateService
{
    public function __construct(
        protected RollbackService $rollbackService,
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function createSafeUpdate(
        \App\Models\Site $site,
        string $type,
        string $slug,
        string $name,
        string $fromVersion,
        string $toVersion
    ): SafeUpdate {
        return SafeUpdate::create([
            'site_id' => $site->id,
            'type' => $type,
            'slug' => $slug,
            'name' => $name,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'status' => 'pending',
            'started_at' => now(),
        ]);
    }

    public function runSafeUpdate(SafeUpdate $safeUpdate, ?int $userId = null): void
    {
        /** @var \App\Models\Site $site */
        $site = $safeUpdate->site;
        $api = $this->apiFactory->make($site);

        try {
            // Step 1: Backup
            $safeUpdate->update(['status' => 'backing_up']);
            $config = $site->backupConfig;
            if ($config) {
                CreateBackup::dispatchSync($site, 'database', 'pre_update', $config->storage_destination_id);
            }

            // Step 2: Update
            $safeUpdate->update(['status' => 'updating']);
            $updateResult = match ($safeUpdate->type) {
                'plugin' => $api->updatePlugins([$safeUpdate->slug]),
                'theme' => $api->updateThemes([$safeUpdate->slug]),
                'core' => $api->updateCore(),
                default => throw new \InvalidArgumentException("Unknown update type: {$safeUpdate->type}"),
            };

            // Step 3: Create rollback point
            $rollbackPoint = $this->rollbackService->createRollbackPoint(
                $site,
                $safeUpdate->type,
                $safeUpdate->slug,
                $safeUpdate->from_version,
                $safeUpdate->to_version
            );

            UpdateLog::create([
                'site_id' => $site->id,
                'user_id' => $userId ?? auth()->id(),
                'type' => $safeUpdate->type,
                'name' => $safeUpdate->name,
                'slug' => $safeUpdate->slug,
                'from_version' => $safeUpdate->from_version,
                'to_version' => $safeUpdate->to_version,
                'success' => true,
                'performed_at' => now(),
            ]);

            // Step 4: Health check
            $safeUpdate->update(['status' => 'health_checking']);
            $healthResults = $this->runHealthChecks($site);

            if ($healthResults['passed']) {
                $safeUpdate->update([
                    'status' => 'completed',
                    'health_check_results' => $healthResults['checks'],
                    'completed_at' => now(),
                ]);
            } else {
                // Health check failed
                if ($safeUpdate->auto_rollback) {
                    $safeUpdate->update(['status' => 'rolling_back']);
                    $this->rollbackService->executeRollback($rollbackPoint);
                }

                $safeUpdate->update([
                    'status' => 'failed',
                    'health_check_results' => $healthResults['checks'],
                    'error_message' => 'Health check failed after update',
                    'completed_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Safe update failed for site {$site->id}: {$e->getMessage()}");
            $safeUpdate->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e;
        }
    }

    public function runHealthChecks(\App\Models\Site $site): array
    {
        try {
            $api = $this->apiFactory->make($site);
            $result = $api->healthCheck();

            $checks = $result['checks'] ?? [];
            $passed = ($result['status'] ?? 'unknown') === 'ok';

            return ['passed' => $passed, 'checks' => $checks];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'checks' => [['name' => 'health_endpoint', 'status' => 'error', 'message' => $e->getMessage()]],
            ];
        }
    }
}
