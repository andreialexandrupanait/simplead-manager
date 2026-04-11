<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\ThemeFileCheck;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class ThemeIntegrityService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function check(Site $site, string $themeSlug, ?string $trackerKey = null): ThemeFileCheck
    {
        Log::info("Theme integrity check started for theme '{$themeSlug}' on site {$site->id}");

        try {
            $api = $this->apiFactory->make($site);
            $result = $api->getThemeIntegrityCheck($themeSlug);

            if ($trackerKey) {
                JobTracker::progress($trackerKey, 40, 'Comparing with baseline...');
            }

            $currentFiles = $result['files'] ?? [];
            $version = $result['version'] ?? null;

            // Find the latest baseline for this theme on this site
            $baseline = ThemeFileCheck::where('site_id', $site->id)
                ->where('theme_slug', $themeSlug)
                ->whereNotNull('baseline_hashes')
                ->latest('checked_at')
                ->first();

            // If no baseline exists, create one
            if (! $baseline) {
                return $this->createBaseline($site, $themeSlug, $version, $currentFiles);
            }

            // If version changed, create new baseline
            if ($baseline->theme_version !== $version) {
                Log::info("Theme '{$themeSlug}' version changed ({$baseline->theme_version} → {$version}), creating new baseline");

                return $this->createBaseline($site, $themeSlug, $version, $currentFiles);
            }

            // Compare current files against baseline
            $baselineHashes = $baseline->baseline_hashes;
            $modified = [];
            $unknown = [];

            foreach ($currentFiles as $path => $hash) {
                if (isset($baselineHashes[$path])) {
                    if ($baselineHashes[$path] !== $hash) {
                        $modified[] = [
                            'path' => $path,
                            'expected_hash' => $baselineHashes[$path],
                            'actual_hash' => $hash,
                        ];
                    }
                } else {
                    $unknown[] = $path;
                }
            }

            $status = (count($modified) > 0 || count($unknown) > 0) ? 'modified' : 'clean';

            if ($trackerKey) {
                JobTracker::progress($trackerKey, 80, 'Saving results...');
            }

            $check = ThemeFileCheck::create([
                'site_id' => $site->id,
                'theme_slug' => $themeSlug,
                'theme_version' => $version,
                'total_files' => count($currentFiles),
                'modified_count' => count($modified),
                'unknown_count' => count($unknown),
                'modified_files' => ! empty($modified) ? $modified : null,
                'unknown_files' => ! empty($unknown) ? $unknown : null,
                'status' => $status,
                'checked_at' => now(),
            ]);

            if ($status === 'modified') {
                NotificationService::notifySiteEvent(
                    site: $site,
                    event: 'theme_files_modified',
                    title: "Theme file integrity issues on {$site->name}",
                    message: count($modified).' modified and '.count($unknown)." unknown files detected in theme '{$themeSlug}'.",
                    fields: [
                        'Theme' => $themeSlug,
                        'Version' => $version ?? 'Unknown',
                        'Modified Files' => (string) count($modified),
                        'Unknown Files' => (string) count($unknown),
                    ],
                    severity: 'critical',
                );
            }

            ActivityLogger::log(
                type: 'security',
                severity: $status === 'clean' ? 'info' : 'warning',
                title: "Theme integrity check for '{$themeSlug}' on {$site->name}",
                description: $status === 'clean'
                    ? 'All theme files match the baseline.'
                    : count($modified).' modified, '.count($unknown).' unknown files.',
                site: $site,
                icon: 'shield',
                url: route('sites.security', $site),
            );

            return $check;
        } catch (\Exception $e) {
            Log::warning("Theme integrity check failed for '{$themeSlug}' on site {$site->id}: {$e->getMessage()}");

            return ThemeFileCheck::create([
                'site_id' => $site->id,
                'theme_slug' => $themeSlug,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'checked_at' => now(),
            ]);
        }
    }

    private function createBaseline(Site $site, string $themeSlug, ?string $version, array $files): ThemeFileCheck
    {
        return ThemeFileCheck::create([
            'site_id' => $site->id,
            'theme_slug' => $themeSlug,
            'theme_version' => $version,
            'total_files' => count($files),
            'modified_count' => 0,
            'unknown_count' => 0,
            'baseline_hashes' => $files,
            'status' => 'baseline',
            'checked_at' => now(),
        ]);
    }
}
