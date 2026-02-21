<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            ['analytics_cache', 'idx_analytics_cache_fetched_at', 'fetched_at'],
            ['search_console_cache', 'idx_search_console_cache_fetched_at', 'fetched_at'],
            ['email_health_checks', 'idx_email_health_checks_checked_at', 'checked_at'],
            ['core_file_checks', 'idx_core_file_checks_checked_at', 'checked_at'],
            ['database_cleanups', 'idx_database_cleanups_cleaned_at', 'cleaned_at'],
            ['cloudflare_cache_purges', 'idx_cloudflare_cache_purges_purged_at', 'purged_at'],
            ['failed_jobs', 'idx_failed_jobs_failed_at', 'failed_at'],
            ['keyword_positions', 'idx_keyword_positions_date', 'date'],
            ['safe_updates', 'idx_safe_updates_completed_at', 'completed_at'],
        ];

        foreach ($indexes as [$table, $indexName, $column]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if ($this->indexExists($table, $indexName)) {
                continue;
            }

            Schema::table($table, function ($t) use ($column, $indexName) {
                $t->index($column, $indexName);
            });
        }
    }

    public function down(): void
    {
        $indexes = [
            ['analytics_cache', 'idx_analytics_cache_fetched_at'],
            ['search_console_cache', 'idx_search_console_cache_fetched_at'],
            ['email_health_checks', 'idx_email_health_checks_checked_at'],
            ['core_file_checks', 'idx_core_file_checks_checked_at'],
            ['database_cleanups', 'idx_database_cleanups_cleaned_at'],
            ['cloudflare_cache_purges', 'idx_cloudflare_cache_purges_purged_at'],
            ['failed_jobs', 'idx_failed_jobs_failed_at'],
            ['keyword_positions', 'idx_keyword_positions_date'],
            ['safe_updates', 'idx_safe_updates_completed_at'],
        ];

        foreach ($indexes as [$table, $indexName]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!$this->indexExists($table, $indexName)) {
                continue;
            }

            Schema::table($table, function ($t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        );
    }
};
