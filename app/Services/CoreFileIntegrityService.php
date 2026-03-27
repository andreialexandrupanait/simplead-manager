<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoreFileCheck;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoreFileIntegrityService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public static function fetchOfficialChecksums(string $version, string $locale = 'en_US'): array
    {
        $response = Http::timeout(15)->get('https://api.wordpress.org/core/checksums/1.0/', [
            'version' => $version,
            'locale' => $locale,
        ]);

        $response->throw();

        $data = $response->json();

        return $data['checksums'] ?? [];
    }

    public function check(Site $site, ?string $trackerKey = null): CoreFileCheck
    {
        $startTime = microtime(true);
        Log::info("Core file integrity check started for site {$site->id} ({$site->name})");

        try {
            $api = $this->apiFactory->make($site);
            $siteFiles = $api->getCoreIntegrityCheck();

            if ($trackerKey) {
                JobTracker::progress($trackerKey, 20, 'Fetching official checksums...');
            }

            $wpVersion = $siteFiles['wp_version'] ?? $site->wp_version;

            if (! $wpVersion) {
                return CoreFileCheck::create([
                    'site_id' => $site->id,
                    'status' => 'error',
                    'error_message' => 'WordPress version could not be determined.',
                    'checked_at' => now(),
                ]);
            }

            $officialChecksums = static::fetchOfficialChecksums($wpVersion);

            if (empty($officialChecksums)) {
                return CoreFileCheck::create([
                    'site_id' => $site->id,
                    'wp_version' => $wpVersion,
                    'status' => 'error',
                    'error_message' => "Could not fetch official checksums for WordPress {$wpVersion}.",
                    'checked_at' => now(),
                ]);
            }

            if ($trackerKey) {
                JobTracker::progress($trackerKey, 40, 'Comparing files...');
            }

            $fileHashes = $siteFiles['files'] ?? [];
            $modified = [];
            $missing = [];
            $unknown = [];

            // Check official files against site files
            foreach ($officialChecksums as $path => $expectedHash) {
                if (isset($fileHashes[$path])) {
                    if ($fileHashes[$path] !== $expectedHash) {
                        $modified[] = [
                            'path' => $path,
                            'expected_hash' => $expectedHash,
                            'actual_hash' => $fileHashes[$path],
                        ];
                    }
                } else {
                    $missing[] = $path;
                }
            }

            // Check for unknown files in wp-admin/wp-includes
            foreach ($fileHashes as $path => $hash) {
                if (! isset($officialChecksums[$path])) {
                    if (str_starts_with($path, 'wp-admin/') || str_starts_with($path, 'wp-includes/')) {
                        $unknown[] = $path;
                    }
                }
            }

            $status = (count($modified) > 0 || count($missing) > 0 || count($unknown) > 0) ? 'modified' : 'clean';

            if ($trackerKey) {
                JobTracker::progress($trackerKey, 80, 'Saving results...');
            }

            $check = CoreFileCheck::create([
                'site_id' => $site->id,
                'wp_version' => $wpVersion,
                'total_files' => count($officialChecksums),
                'modified_count' => count($modified),
                'missing_count' => count($missing),
                'unknown_count' => count($unknown),
                'modified_files' => ! empty($modified) ? $modified : null,
                'missing_files' => ! empty($missing) ? $missing : null,
                'unknown_files' => ! empty($unknown) ? $unknown : null,
                'status' => $status,
                'checked_at' => now(),
            ]);

            if ($status === 'modified') {
                NotificationService::notifySiteEvent(
                    site: $site,
                    event: 'core_files_modified',
                    title: "Core file integrity issues on {$site->name}",
                    message: count($modified).' modified, '.count($missing).' missing, '.count($unknown).' unknown files detected.',
                    fields: [
                        'WordPress Version' => $wpVersion,
                        'Modified Files' => (string) count($modified),
                        'Missing Files' => (string) count($missing),
                        'Unknown Files' => (string) count($unknown),
                    ],
                    severity: 'critical',
                );
            }

            if ($trackerKey) {
                JobTracker::progress($trackerKey, 95, 'Finalizing...');
            }

            $duration = round(microtime(true) - $startTime, 2);
            Log::info("Core file integrity check completed for site {$site->id}", [
                'status' => $status,
                'modified' => count($modified),
                'missing' => count($missing),
                'unknown' => count($unknown),
                'duration_seconds' => $duration,
            ]);

            ActivityLogger::log(
                type: 'security',
                severity: $status === 'clean' ? 'info' : 'warning',
                title: "Core file integrity check for {$site->name}",
                description: $status === 'clean'
                    ? 'All core files are clean.'
                    : count($modified).' modified, '.count($missing).' missing, '.count($unknown).' unknown files.',
                site: $site,
                icon: 'shield',
                url: route('sites.security', $site),
            );

            return $check;
        } catch (\Exception $e) {
            Log::warning("Core file integrity check failed for site {$site->id}: {$e->getMessage()}");

            return CoreFileCheck::create([
                'site_id' => $site->id,
                'wp_version' => $site->wp_version,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'checked_at' => now(),
            ]);
        }
    }
}
