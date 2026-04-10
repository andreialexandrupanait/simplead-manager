<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DatabaseCleanup;
use App\Models\Site;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class DatabaseCleanupService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function getStats(Site $site): array
    {
        $api = $this->apiFactory->make($site);
        $response = $api->getDbCleanupStats();

        $stats = $response['stats'] ?? $response;

        // Aggregate orphaned meta into a single key for the UI
        if (isset($stats['orphaned_postmeta'])) {
            $stats['orphaned_meta'] = ($stats['orphaned_postmeta'] ?? 0)
                + ($stats['orphaned_commentmeta'] ?? 0)
                + ($stats['orphaned_usermeta'] ?? 0)
                + ($stats['orphaned_termmeta'] ?? 0);
        }

        // Normalize key names for the UI
        if (isset($stats['expired_transients']) && ! isset($stats['transients'])) {
            $stats['transients'] = $stats['expired_transients'];
        }
        if (isset($stats['trashed_posts']) && ! isset($stats['trash_posts'])) {
            $stats['trash_posts'] = $stats['trashed_posts'];
        }

        return $stats;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function optimizeTable(Site $site, string $tableName): array
    {
        try {
            $api = $this->apiFactory->make($site);
            $api->optimizeTable($tableName);

            ActivityLogger::log(
                type: 'database',
                severity: 'info',
                title: "Table optimized on {$site->name}",
                description: "Optimized table: {$tableName}",
                site: $site,
                icon: 'database',
                url: route('sites.database', $site),
            );

            return ['success' => true, 'message' => "Table '{$tableName}' optimized successfully."];
        } catch (RequestException|\RuntimeException $e) {
            Log::warning("Table optimize failed for {$tableName} on site {$site->id}: {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function convertTableEngine(Site $site, string $tableName): array
    {
        try {
            $api = $this->apiFactory->make($site);
            $api->convertTableEngine($tableName);

            ActivityLogger::log(
                type: 'database',
                severity: 'info',
                title: "Table engine converted on {$site->name}",
                description: "Converted '{$tableName}' to InnoDB.",
                site: $site,
                icon: 'database',
                url: route('sites.database', $site),
            );

            return ['success' => true, 'message' => "Table '{$tableName}' converted to InnoDB."];
        } catch (RequestException|\RuntimeException $e) {
            Log::warning("Table engine conversion failed for {$tableName} on site {$site->id}: {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteTable(Site $site, string $tableName): array
    {
        try {
            $api = $this->apiFactory->make($site);
            $api->deleteTable($tableName);

            ActivityLogger::log(
                type: 'database',
                severity: 'warning',
                title: "Table deleted on {$site->name}",
                description: "Deleted table: {$tableName}",
                site: $site,
                icon: 'database',
                url: route('sites.database', $site),
            );

            return ['success' => true, 'message' => "Table '{$tableName}' deleted."];
        } catch (RequestException|\RuntimeException $e) {
            Log::warning("Table delete failed for {$tableName} on site {$site->id}: {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function run(Site $site, array $options): DatabaseCleanup
    {
        try {
            $api = $this->apiFactory->make($site);
            $response = $api->runDbCleanup($options);

            // Extract cleaned counts from connector response
            $cleaned = $response['cleaned'] ?? $response;

            $orphanedMeta = ($cleaned['orphaned_postmeta'] ?? 0)
                + ($cleaned['orphaned_commentmeta'] ?? 0)
                + ($cleaned['orphaned_usermeta'] ?? 0)
                + ($cleaned['orphaned_termmeta'] ?? 0);

            $cleanup = DatabaseCleanup::create([
                'site_id' => $site->id,
                'revisions_deleted' => $cleaned['revisions'] ?? 0,
                'auto_drafts_deleted' => $cleaned['auto_drafts'] ?? 0,
                'trash_posts_deleted' => $cleaned['trashed_posts'] ?? 0,
                'spam_comments_deleted' => $cleaned['spam_comments'] ?? 0,
                'trash_comments_deleted' => $cleaned['trashed_comments'] ?? 0,
                'transients_deleted' => $cleaned['expired_transients'] ?? 0,
                'orphaned_meta_deleted' => $orphanedMeta,
                'space_saved' => $cleaned['space_saved'] ?? 0,
                'status' => 'completed',
                'cleaned_at' => now(),
            ]);

            ActivityLogger::log(
                type: 'database',
                severity: 'info',
                title: "Database cleanup completed for {$site->name}",
                description: "{$cleanup->total_deleted} items deleted, {$cleanup->formatted_space_saved} saved.",
                site: $site,
                icon: 'database',
                url: route('sites.database', $site),
            );

            return $cleanup;
        } catch (RequestException|\RuntimeException $e) {
            Log::warning("Database cleanup failed for site {$site->id}: {$e->getMessage()}");

            $cleanup = DatabaseCleanup::create([
                'site_id' => $site->id,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'cleaned_at' => now(),
            ]);

            ActivityLogger::log(
                type: 'database',
                severity: 'warning',
                title: "Database cleanup failed for {$site->name}",
                description: $e->getMessage(),
                site: $site,
                icon: 'database',
                url: route('sites.database', $site),
            );

            return $cleanup;
        }
    }
}
