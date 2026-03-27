<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DatabaseHealthCheck;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class DatabaseHealthService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function check(Site $site, ?string $trackerKey = null): DatabaseHealthCheck
    {
        $startTime = microtime(true);
        Log::info("Database health check started for site {$site->id} ({$site->name})");

        $api = $this->apiFactory->make($site);
        $data = $api->getDatabaseHealth();

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 40, 'Analyzing tables...');
        }

        $tablesData = $data['tables'] ?? [];
        $totalSize = 0;
        $totalTables = count($tablesData);
        $myisamCount = 0;
        $autoloadSize = $data['autoload_size'] ?? 0;

        foreach ($tablesData as $table) {
            $totalSize += ($table['data_size'] ?? 0) + ($table['index_size'] ?? 0);
            if (strtolower($table['engine'] ?? '') === 'myisam') {
                $myisamCount++;
            }
        }

        // Largest tables (top 10)
        $sortedTables = collect($tablesData)->sortByDesc(fn ($t) => ($t['data_size'] ?? 0) + ($t['index_size'] ?? 0))->take(10)->values()->all();

        // Tables with overhead
        $tablesWithOverhead = collect($tablesData)->filter(fn ($t) => ($t['overhead'] ?? 0) > 0)->values()->all();

        // Determine status using configurable thresholds
        $warnings = 0;
        if ($totalSize > config('monitoring.db_total_size_warning', 1_073_741_824)) {
            $warnings++;
        }
        if ($autoloadSize > config('monitoring.db_autoload_size_warning', 1_048_576)) {
            $warnings++;
        }
        if ($myisamCount > 0) {
            $warnings++;
        }

        $totalOverhead = collect($tablesWithOverhead)->sum('overhead');
        if ($totalOverhead > config('monitoring.db_overhead_warning', 104_857_600)) {
            $warnings++;
        }

        $tableSizeWarning = config('monitoring.db_table_size_warning', 524_288_000);
        foreach ($sortedTables as $table) {
            $tableSize = ($table['data_size'] ?? 0) + ($table['index_size'] ?? 0);
            if ($tableSize > $tableSizeWarning) {
                $warnings++;
            }
        }

        $status = match (true) {
            $warnings >= 3 => 'critical',
            $warnings >= 1 => 'warning',
            default => 'healthy',
        };

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 80, 'Saving results...');
        }

        $healthCheck = DatabaseHealthCheck::create([
            'site_id' => $site->id,
            'total_size' => $totalSize,
            'total_tables' => $totalTables,
            'tables_data' => $tablesData,
            'largest_tables' => $sortedTables,
            'tables_with_overhead' => $tablesWithOverhead,
            'myisam_count' => $myisamCount,
            'autoload_size' => $autoloadSize,
            'status' => $status,
            'checked_at' => now(),
        ]);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 95, 'Finalizing...');
        }

        $duration = round(microtime(true) - $startTime, 2);
        Log::info("Database health check completed for site {$site->id}", [
            'status' => $status,
            'total_size' => $totalSize,
            'total_tables' => $totalTables,
            'warnings' => $warnings,
            'duration_seconds' => $duration,
        ]);

        ActivityLogger::log(
            type: 'database',
            severity: $status === 'healthy' ? 'info' : 'warning',
            title: "Database health check for {$site->name}",
            description: "Status: {$status}, Size: {$healthCheck->formatted_total_size}, Tables: {$totalTables}",
            site: $site,
            icon: 'database',
            url: route('sites.database', $site),
        );

        return $healthCheck;
    }
}
