<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CreateBackup;
use App\Models\SafeUpdate;
use App\Models\UpdateLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class SafeUpdateService
{
    private const VISUAL_DIFF_THRESHOLD = 15.0;

    public function __construct(
        protected RollbackService $rollbackService,
        protected WordPressApiServiceFactory $apiFactory,
        protected ScreenshotService $screenshotService,
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

            // Step 2: Pre-update screenshot
            $beforeScreenshot = $this->captureScreenshot($site, $safeUpdate, 'before');

            // Step 3: Update
            $safeUpdate->update(['status' => 'updating']);
            $updateResult = match ($safeUpdate->type) {
                'plugin' => $api->updatePlugins([$safeUpdate->slug]),
                'theme' => $api->updateThemes([$safeUpdate->slug]),
                'core' => $api->updateCore(),
                default => throw new \InvalidArgumentException("Unknown update type: {$safeUpdate->type}"),
            };

            // Step 4: Create rollback point
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

            // Step 5: Health check
            $safeUpdate->update(['status' => 'health_checking']);
            $healthResults = $this->runHealthChecks($site);

            // Step 6: Visual regression check
            $visualResults = null;
            if ($beforeScreenshot) {
                $visualResults = $this->runVisualRegression($site, $safeUpdate, $beforeScreenshot);
            }

            $healthPassed = $healthResults['passed'];
            $visualPassed = ! $visualResults || ($visualResults['diff_percent'] ?? 0) < self::VISUAL_DIFF_THRESHOLD;

            if ($healthPassed && $visualPassed) {
                $safeUpdate->update([
                    'status' => 'completed',
                    'health_check_results' => $healthResults['checks'],
                    'visual_regression_results' => $visualResults,
                    'completed_at' => now(),
                ]);
            } else {
                $errorParts = [];
                if (! $healthPassed) {
                    $errorParts[] = 'Health check failed';
                }
                if (! $visualPassed) {
                    $errorParts[] = 'Visual regression detected significant changes (' . ($visualResults['diff_percent'] ?? '?') . '% different)';
                }

                if ($safeUpdate->auto_rollback) {
                    $safeUpdate->update(['status' => 'rolling_back']);
                    $this->rollbackService->executeRollback($rollbackPoint);
                }

                $safeUpdate->update([
                    'status' => 'failed',
                    'health_check_results' => $healthResults['checks'],
                    'visual_regression_results' => $visualResults,
                    'error_message' => implode('. ', $errorParts),
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
        } catch (RequestException|\RuntimeException $e) {
            return [
                'passed' => false,
                'checks' => [['name' => 'health_endpoint', 'status' => 'error', 'message' => $e->getMessage()]],
            ];
        }
    }

    protected function captureScreenshot(\App\Models\Site $site, SafeUpdate $safeUpdate, string $label): ?string
    {
        try {
            $binary = $this->screenshotService->capture($site->url);
            if ($binary) {
                $path = $this->screenshotService->save($binary, $site->id, $safeUpdate->id, $label);
                $safeUpdate->update(["screenshot_{$label}_path" => $path]);

                return $binary;
            }
        } catch (\Throwable $e) {
            Log::warning("Screenshot capture ({$label}) failed for site {$site->id}: {$e->getMessage()}");
        }

        return null;
    }

    protected function runVisualRegression(\App\Models\Site $site, SafeUpdate $safeUpdate, string $beforeBinary): ?array
    {
        try {
            $afterBinary = $this->screenshotService->capture($site->url);
            if (! $afterBinary) {
                return null;
            }

            $this->screenshotService->save($afterBinary, $site->id, $safeUpdate->id, 'after');
            $safeUpdate->update(['screenshot_after_path' => "update-screenshots/{$site->id}/{$safeUpdate->id}/after.jpg"]);

            return $this->screenshotService->compare($beforeBinary, $afterBinary);
        } catch (\Throwable $e) {
            Log::warning("Visual regression check failed for site {$site->id}: {$e->getMessage()}");

            return null;
        }
    }
}
