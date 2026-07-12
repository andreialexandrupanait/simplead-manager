<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\SyncWordPressSite;
use App\Models\Backup;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\Notifications\NotificationService;
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

    /**
     * @param  string|null  $heldLockToken  SiteOperationLock token owned by the
     *                                      caller (RunSafeUpdate). Passed down to
     *                                      the pre-update backup so it runs under
     *                                      the held lock instead of contending
     *                                      with it and silently no-opping (P0-07).
     */
    public function runSafeUpdate(SafeUpdate $safeUpdate, ?int $userId = null, ?string $heldLockToken = null): void
    {
        /** @var \App\Models\Site $site */
        $site = $safeUpdate->site;
        $api = $this->apiFactory->make($site);

        try {
            // Step 1: Pre-update safety backup. This is the safety net the whole
            // "safe update" promise rests on — if it does not verifiably complete
            // we HARD-ABORT rather than update a client site with no way back
            // (P0-07). It runs under the caller's site lock ($heldLockToken) so it
            // cannot be silently skipped by lock contention.
            $safeUpdate->update(['status' => 'backing_up']);
            $config = $site->backupConfig;

            // No backup configuration means no rollback point can be produced.
            // A "safe" update with no safety net is worse than a plain update, so
            // rather than silently skipping the backup and touching a client site
            // anyway (the previous behaviour), hard-abort and escalate — the same
            // fail-closed posture as the P0-07 backup-not-verified abort (P1-17).
            if (! $config) {
                $reason = 'No backup configuration exists for this site, so no pre-update safety '
                    .'backup can be taken; aborting the safe update rather than changing a client site '
                    .'with no rollback point. Configure backups for this site to enable safe updates.';
                $this->abortSafeUpdate($safeUpdate, $reason);

                throw new \RuntimeException($reason);
            }

            $preBackup = $this->runPreUpdateBackup($site, $config, $heldLockToken);

            if (! $preBackup || $preBackup->status !== BackupStatus::Completed) {
                $reason = 'Pre-update safety backup did not complete (it was skipped or failed); '
                    .'aborting the update to avoid changing the site with no rollback point.';
                $this->abortSafeUpdate($safeUpdate, $reason);

                throw new \RuntimeException($reason);
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
                $failureReason = $this->stringifyUpdateError($updateResult['error'] ?? null)
                    ?? 'Update did not apply on the target site.';
                $safeUpdate->update([
                    'status' => 'failed',
                    'error_message' => $failureReason,
                    'completed_at' => now(),
                ]);

                // The connector rejected the update — surface it so a security
                // remediation is never left silently unapplied (P1-19/P1-42).
                $this->notifySafeUpdateOutcome(
                    $safeUpdate,
                    'safe_update_failed',
                    'Safe Update Failed',
                    "The update for {$safeUpdate->name} did not apply on {$site->name}: {$failureReason}",
                    rolledBack: false,
                );

                return;
            }

            // Step 4: Create rollback point (only after a real, successful update).
            //
            // P2-26: the connector's rollback endpoint reinstalls the target from
            // downloads.wordpress.org, which 404s for premium / custom-hosted
            // plugins (WooCommerce extensions, ACF Pro, …). Minting a wp.org-style
            // rollback point for such a plugin gives a false safety net that fails
            // at rollback time. For anything we positively know is not
            // wordpress.org-hosted we skip that point and rely on the pre-update
            // FULL backup already taken as the recovery path, logging clearly.
            $rollbackPoint = null;
            if ($this->supportsWpOrgRollback($site, $safeUpdate)) {
                $rollbackPoint = $this->rollbackService->createRollbackPoint(
                    $site,
                    $safeUpdate->type,
                    $safeUpdate->slug,
                    $safeUpdate->from_version,
                    $safeUpdate->to_version
                );
            } else {
                Log::warning(
                    "Safe update {$safeUpdate->id}: '{$safeUpdate->name}' on site {$site->id} is not "
                    .'wordpress.org-hosted; skipping the wp.org reinstall rollback point (it would 404). '
                    .'Recovery path is the pre-update full backup.'
                );
            }

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

                // P2-25: the update verifiably succeeded, so reconcile local
                // inventory — otherwise the UI keeps offering an already-applied
                // update and a second click reruns the whole pipeline (duplicate
                // backup/log/rollback point).
                $this->reconcileAfterSuccessfulUpdate($safeUpdate, $site, $appliedTo);
            } else {
                $errorParts = [];
                if (! $healthPassed) {
                    $errorParts[] = 'Health check failed';
                }
                if (! $visualPassed) {
                    $errorParts[] = 'Visual regression detected significant changes ('.($visualResults['diff_percent'] ?? '?').'% different)';
                }

                $errorMessage = implode('. ', $errorParts);

                // P2-26/P2-28: only attempt an automatic wp.org rollback when a
                // rollback point was actually minted (a premium plugin has none),
                // and treat the rollback as done ONLY when it genuinely reports
                // success — never defaulting a missing/false payload to success.
                $rolledBack = false;
                if ($safeUpdate->auto_rollback && $rollbackPoint !== null) {
                    $safeUpdate->update(['status' => 'rolling_back']);
                    $rollbackResult = $this->rollbackService->executeRollback($rollbackPoint, $userId);
                    $rolledBack = ($rollbackResult['success'] ?? null) === true;
                }

                $safeUpdate->update([
                    'status' => 'failed',
                    'health_check_results' => $healthResults['checks'],
                    'visual_regression_results' => $visualResults,
                    'error_message' => $errorMessage,
                    'completed_at' => now(),
                ]);

                // P1-42: these branches previously completed silently. An operator
                // must be told when a client site failed its post-update checks —
                // and especially when we automatically rolled it back.
                if ($rolledBack) {
                    $this->notifySafeUpdateOutcome(
                        $safeUpdate,
                        'safe_update_rolled_back',
                        'Safe Update Rolled Back',
                        "Post-update checks failed for {$safeUpdate->name} on {$site->name}; the site was "
                            ."automatically rolled back to {$safeUpdate->from_version}. {$errorMessage}.",
                        rolledBack: true,
                    );
                } else {
                    // Distinguish WHY the site was not rolled back so the operator
                    // knows the recovery path (P2-26: premium plugins fall back to
                    // the pre-update backup).
                    if ($rollbackPoint === null) {
                        $reasonSuffix = 'Automatic wp.org rollback is not available for this non-wordpress.org '
                            .'item — restore the pre-update backup to recover.';
                    } elseif (! $safeUpdate->auto_rollback) {
                        $reasonSuffix = 'Auto-rollback is off — the site may be unhealthy and was NOT rolled back.';
                    } else {
                        $reasonSuffix = 'Automatic rollback was attempted but did not succeed — restore the '
                            .'pre-update backup to recover.';
                    }

                    $this->notifySafeUpdateOutcome(
                        $safeUpdate,
                        'safe_update_failed',
                        'Safe Update Unhealthy',
                        "Post-update checks failed for {$safeUpdate->name} on {$site->name}. {$reasonSuffix} {$errorMessage}.",
                        rolledBack: false,
                    );
                }
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
     * P2-26: decide whether the connector's wp.org reinstall rollback endpoint can
     * recover this target. Core is always served from wordpress.org. For a plugin
     * we only refuse when local inventory positively marks it as NOT wp.org-hosted
     * (`is_on_wp_org === false`); unknown/missing inventory and themes keep the
     * prior behaviour so genuine wp.org items are unaffected.
     */
    protected function supportsWpOrgRollback(Site $site, SafeUpdate $safeUpdate): bool
    {
        if ($safeUpdate->type !== 'plugin') {
            return true;
        }

        /** @var \App\Models\SitePlugin|null $plugin */
        $plugin = $site->sitePlugins()->where('slug', $safeUpdate->slug)->first();

        return ! ($plugin !== null && $plugin->is_on_wp_org === false);
    }

    /**
     * P2-25: after a verified-successful safe update, refresh the local inventory
     * so the UI stops offering the update and the pending-updates badge is correct
     * immediately, then dispatch an authoritative sync to confirm. Idempotent: the
     * badge is only decremented while the local row still shows `has_update = true`,
     * so a re-run cannot double-decrement.
     */
    protected function reconcileAfterSuccessfulUpdate(SafeUpdate $safeUpdate, Site $site, string $newVersion): void
    {
        if ($safeUpdate->type === 'plugin') {
            /** @var \App\Models\SitePlugin|null $plugin */
            $plugin = $site->sitePlugins()->where('slug', $safeUpdate->slug)->first();
            if ($plugin && $plugin->has_update) {
                $plugin->update([
                    'version' => $newVersion,
                    'has_update' => false,
                    'update_version' => null,
                ]);
                $this->decrementPendingUpdates($site);
            }
        } elseif ($safeUpdate->type === 'theme') {
            /** @var \App\Models\SiteTheme|null $theme */
            $theme = $site->siteThemes()->where('slug', $safeUpdate->slug)->first();
            if ($theme && $theme->has_update) {
                $theme->update([
                    'version' => $newVersion,
                    'has_update' => false,
                    'update_version' => null,
                ]);
                $this->decrementPendingUpdates($site);
            }
        }

        // Reconcile authoritative state (covers core, and confirms the lightweight
        // local update above against the live site).
        SyncWordPressSite::dispatch($site);
    }

    private function decrementPendingUpdates(Site $site): void
    {
        $current = (int) ($site->pending_updates_count ?? 0);
        if ($current <= 0) {
            return;
        }

        $site->update(['pending_updates_count' => $current - 1]);
    }

    /**
     * Run the pre-update DB backup synchronously under the caller's site lock
     * and return the resulting Backup row (or null if none was produced) so the
     * caller can verify it actually completed before touching the site.
     *
     * Extracted + protected so the hard-abort path (P0-07) is unit-testable
     * without driving the full CreateBackup pipeline against a live connector.
     */
    protected function runPreUpdateBackup(Site $site, \App\Models\BackupConfig $config, ?string $heldLockToken): ?Backup
    {
        // Plugin/theme/core updates change FILES on disk, so a DB-only backup is
        // not a rollback point for the thing being changed — a file-corrupting
        // update would be unrecoverable. Take a FULL (files + database) backup so
        // the safety net actually covers the update (P1-17).
        CreateBackup::dispatchSync(
            $site,
            'full',
            'pre_update',
            $config->storage_destination_id,
            null,
            false,
            $heldLockToken,
        );

        return Backup::where('site_id', $site->id)
            ->where('trigger', 'pre_update')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Mark a safe update failed with a reason and log it. Used by the fail-closed
     * abort paths (no backup config / backup did not complete).
     */
    private function abortSafeUpdate(SafeUpdate $safeUpdate, string $reason): void
    {
        $safeUpdate->update([
            'status' => 'failed',
            'error_message' => $reason,
            'completed_at' => now(),
        ]);

        Log::error("Safe update {$safeUpdate->id} aborted for site {$safeUpdate->site_id}: {$reason}");
    }

    /**
     * Emit a critical notification for a safe-update outcome an operator must see
     * (failed to apply, rolled back, or left unhealthy). Previously these
     * non-exception paths finished silently (P1-42).
     */
    private function notifySafeUpdateOutcome(SafeUpdate $safeUpdate, string $event, string $title, string $message, bool $rolledBack): void
    {
        /** @var Site $site */
        $site = $safeUpdate->site;

        NotificationService::notifySiteEvent(
            $site,
            $event,
            $title,
            $message,
            [
                'Type' => $safeUpdate->type,
                'Name' => $safeUpdate->name,
                'Version' => "{$safeUpdate->from_version} → {$safeUpdate->to_version}",
                'Rolled back' => $rolledBack ? 'yes' : 'no',
            ],
            'critical',
        );
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
