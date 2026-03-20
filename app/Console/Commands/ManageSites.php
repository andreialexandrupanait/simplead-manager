<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageSites extends Command
{
    protected $signature = 'app:manage-sites
                            {--force-delete= : Permanently remove a site by ID and all related data}';

    protected $description = 'List and manage sites, including soft-deleted ones';

    public function handle(): int
    {
        if ($id = $this->option('force-delete')) {
            return $this->forceDeleteSite((int) $id);
        }

        return $this->listSites();
    }

    private function listSites(): int
    {
        $sites = Site::withTrashed()->orderBy('id')->get();

        if ($sites->isEmpty()) {
            $this->info('No sites found.');
            return self::SUCCESS;
        }

        $rows = $sites->map(fn (Site $site) => [
            $site->id,
            $site->name,
            $site->url,
            $site->user_id,
            $site->is_connected ? 'Yes' : 'No',
            $site->deleted_at?->toDateTimeString() ?? '—',
            $site->created_at?->toDateTimeString(),
        ]);

        $this->table(
            ['ID', 'Name', 'URL', 'User ID', 'Connected', 'Deleted At', 'Created At'],
            $rows,
        );

        $this->newLine();
        $this->info("Total: {$sites->count()} sites ({$sites->whereNotNull('deleted_at')->count()} soft-deleted)");

        return self::SUCCESS;
    }

    private function forceDeleteSite(int $id): int
    {
        $site = Site::withTrashed()->find($id);

        if (!$site) {
            $this->error("Site #{$id} not found.");
            return self::FAILURE;
        }

        $this->warn("About to permanently delete: {$site->name} ({$site->url})");
        $this->warn("This will remove ALL related data (monitors, settings, backups, etc.).");

        if (!$this->confirm('Are you sure?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($site) {
            // HasMany relationships — delete all related records
            $relations = [
                'uptimeMonitor', 'sslCertificate', 'domainMonitor',
                'performanceMonitor', 'securityMonitor', 'backupConfig',
                'analyticsConnection', 'searchConsoleConnection', 'siteCloudflare',
                'databaseCleanupConfig', 'healthState', 'reportConfig',
                'sitePlugins', 'siteThemes', 'siteUsers',
                'securitySettings', 'securityCommands', 'securityActivityLogs',
                'securityBannedIps', 'securityIpLists',
                'securityScans', 'securityIssues', 'securityRecommendations',
                'vulnerabilityAlerts', 'coreFileChecks',
                'backups', 'updateLogs',
                'analyticsCaches', 'searchConsoleCaches',
                'reportSchedules', 'reports', 'reportRecommendations',
                'activityLogs', 'monthlySnapshots',
                'databaseCleanups', 'databaseHealthChecks', 'emailHealthChecks',
                'sitePluginConflicts', 'rollbackPoints', 'safeUpdates',
                'trackedKeywords',
            ];

            foreach ($relations as $relation) {
                if (method_exists($site, $relation)) {
                    $site->{$relation}()->delete();
                }
            }

            // BelongsToMany pivot
            $site->securityPresets()->detach();

            // Force-delete the site itself
            $site->forceDelete();
        });

        $this->info("Site #{$id} ({$site->name}) permanently deleted with all related data.");

        return self::SUCCESS;
    }
}
