<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DatabaseCleanup;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class DatabaseCleanupService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function getStats(Site $site): array
    {
        $api = $this->apiFactory->make($site);

        return $api->getDbCleanupStats();
    }

    public function run(Site $site, array $options): DatabaseCleanup
    {
        try {
            $api = $this->apiFactory->make($site);
            $result = $api->runDbCleanup($options);

            $cleanup = DatabaseCleanup::create([
                'site_id' => $site->id,
                'revisions_deleted' => $result['revisions_deleted'] ?? 0,
                'auto_drafts_deleted' => $result['auto_drafts_deleted'] ?? 0,
                'trash_posts_deleted' => $result['trash_posts_deleted'] ?? 0,
                'spam_comments_deleted' => $result['spam_comments_deleted'] ?? 0,
                'trash_comments_deleted' => $result['trash_comments_deleted'] ?? 0,
                'transients_deleted' => $result['transients_deleted'] ?? 0,
                'orphaned_meta_deleted' => $result['orphaned_meta_deleted'] ?? 0,
                'space_saved' => $result['space_saved'] ?? 0,
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
        } catch (\Exception $e) {
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
