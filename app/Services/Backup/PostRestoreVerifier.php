<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Backup;
use Illuminate\Support\Facades\Log;

class PostRestoreVerifier
{
    /**
     * Run post-restore verification: clear caches, fix Elementor, run diagnostics.
     * Each step is best-effort — failures are logged but don't block the restore.
     */
    public function verify(
        WordPressApiServiceInterface $api,
        Backup $backup,
        bool $pluginWasUpdated,
        \Closure $reportProgress,
    ): string {
        $results = [];

        if (! $pluginWasUpdated) {
            $results[] = 'plugin update failed';
        }

        // 1. Clear caches (OPcache, object cache, transients)
        $reportProgress('verification', 88, 'Clearing caches...');
        try {
            $cacheResult = $api->clearCache();
            $results[] = 'cache cleared';
            Log::info("Post-restore: cache cleared for backup {$backup->id}", $cacheResult);
        } catch (\Exception $e) {
            $results[] = 'cache clear failed';
            Log::warning("Post-restore: cache clear failed for backup {$backup->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Fix Elementor data: repair null dynamic tag settings + sync versions
        // Must run BEFORE deactivate/reactivate so that when Elementor reactivates
        // and potentially regenerates CSS, the dynamic tags won't crash.
        $reportProgress('verification', 90, 'Fixing Elementor data...');
        try {
            $elementorFix = $api->fixElementor();
            $fixDetails = $elementorFix['fixed'] ?? [];
            $results[] = 'Elementor fixed ('.count($fixDetails).' steps)';
            Log::info("Post-restore: Elementor fix for backup {$backup->id}", $fixDetails);
        } catch (\Exception $e) {
            $results[] = 'Elementor fix skipped';
            Log::warning("Post-restore: Elementor fix failed for backup {$backup->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Reinitialize Elementor: deactivate → clear caches → reactivate
        // After a restore, file timestamps change which causes Elementor to force
        // CSS regeneration. The deactivate/reactivate cycle forces clean initialization.
        $reportProgress('verification', 92, 'Reinitializing Elementor...');
        try {
            try {
                $api->deactivatePlugin('elementor-pro/elementor-pro.php');
            } catch (\Exception $e) {
                // Pro might not be installed
            }

            try {
                $api->deactivatePlugin('elementor/elementor.php');
            } catch (\Exception $e) {
                $results[] = 'Elementor not installed';
                throw $e;
            }

            Log::info("Post-restore: deactivated Elementor for backup {$backup->id}");

            $reportProgress('verification', 93, 'Clearing runtime caches...');
            $api->clearCache();

            $reportProgress('verification', 94, 'Reactivating Elementor...');
            $api->activatePlugin('elementor/elementor.php');
            try {
                $api->activatePlugin('elementor-pro/elementor-pro.php');
            } catch (\Exception $e) {
                // Pro might not be installed
            }

            $api->clearCache();
            $results[] = 'Elementor reinitialized';
            Log::info("Post-restore: Elementor reinitialized for backup {$backup->id}");
        } catch (\Exception $e) {
            if (! str_contains($e->getMessage(), 'not installed')) {
                $results[] = 'Elementor reinit skipped';
                Log::warning("Post-restore: Elementor reinit failed for backup {$backup->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Run diagnostic
        $reportProgress('verification', 96, 'Running diagnostic...');
        try {
            $diagnostic = $api->runDiagnostic();

            $loopback = $diagnostic['loopback'] ?? null;
            if ($loopback && isset($loopback['status'])) {
                $results[] = "site check (HTTP {$loopback['status']})";
            }

            $paused = $diagnostic['paused_extensions'] ?? [];
            if (! empty($paused)) {
                $results[] = 'WARNING: paused extensions detected';
                Log::warning("Post-restore: paused extensions for backup {$backup->id}", ['paused' => $paused]);
            }

            Log::info("Post-restore: diagnostic for backup {$backup->id}", [
                'loopback_status' => $loopback['status'] ?? null,
                'paused_extensions' => $paused,
            ]);
        } catch (\Exception $e) {
            $results[] = 'diagnostic skipped';
            Log::warning("Post-restore: diagnostic failed for backup {$backup->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        return implode('; ', $results);
    }
}
