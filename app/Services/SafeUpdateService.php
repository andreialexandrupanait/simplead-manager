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
        string $toVersion,
        ?string $target = null
    ): SafeUpdate {
        return SafeUpdate::create([
            'site_id' => $site->id,
            'type' => $type,
            'slug' => $slug,
            // Plugins update by their plugin FILE, not their slug; the connector
            // rejects a bare slug as an invalid path. Themes/core fall back to slug.
            'target' => $target,
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

            // Step 3: Update. Plugins must be addressed by their plugin file
            // (e.g. "akismet/akismet.php"); the connector rejects a bare slug
            // as an invalid path. Themes/core fall back to the slug.
            $safeUpdate->update(['status' => 'updating']);
            $identifier = $safeUpdate->target ?: $safeUpdate->slug;
            $response = match ($safeUpdate->type) {
                'plugin' => $api->updatePlugins([$identifier]),
                'theme' => $api->updateThemes([$identifier]),
                'core' => $api->updateCore(),
                default => throw new \InvalidArgumentException("Unknown update type: {$safeUpdate->type}"),
            };

            // Per-item result for plugin/theme; core reports at the top level.
            $updateResult = $safeUpdate->type === 'core'
                ? $response
                : ($response['results'][$identifier] ?? []);

            $updateSucceeded = (bool) ($updateResult['success'] ?? false);
            $appliedFrom = $updateResult['from_version'] ?? $safeUpdate->from_version;
            $appliedTo = $updateResult['to_version'] ?? $safeUpdate->to_version;

            UpdateLog::create([
                'site_id' => $site->id,
                'user_id' => $userId ?? auth()->id(),
                'type' => $safeUpdate->type,
                'name' => $safeUpdate->name,
                'slug' => $safeUpdate->slug,
                'from_version' => $appliedFrom,
                'to_version' => $appliedTo,
                'success' => $updateSucceeded,
                'error_message' => $this->stringifyUpdateError($updateResult['error'] ?? null),
                'performed_at' => now(),
            ]);

            // The update itself did not apply — there is nothing to health-check
            // or roll back to. Record the real failure instead of a false success
            // so a security remediation is never reported as done when it wasn't.
            if (! $updateSucceeded) {
                $safeUpdate->update([
                    'status' => 'failed',
                    'error_message' => $this->stringifyUpdateError($updateResult['error'] ?? null)
                        ?? 'Update did not apply on the target site.',
                    'completed_at' => now(),
                ]);

                return;
            }

            // Step 4: Create rollback point (only after a real, successful update)
            $rollbackPoint = $this->rollbackService->createRollbackPoint(
                $site,
                $safeUpdate->type,
                $safeUpdate->slug,
                $safeUpdate->from_version,
                $safeUpdate->to_version
            );

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
                    $errorParts[] = 'Visual regression detected significant changes ('.($visualResults['diff_percent'] ?? '?').'% different)';
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

    /**
     * The connector reports a per-item error as a string, but a transport-level
     * failure can surface as an array; normalise both to a stored message.
     */
    private function stringifyUpdateError(mixed $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        if (is_string($error)) {
            return $error;
        }

        $encoded = json_encode($error);

        return $encoded === false ? null : $encoded;
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
