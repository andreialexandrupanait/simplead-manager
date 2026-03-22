<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::selectOne(
                "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName]
            );

            return $result !== null;
        }

        return DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        ) !== null;
    }

    public function up(): void
    {
        // Backups — composite [status, created_at]
        if (! $this->indexExists('backups', 'backups_status_created_at_index')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->index(['status', 'created_at']);
            });
        }

        // Notification logs — composite [notification_channel_id, created_at]
        if (! $this->indexExists('notification_logs', 'notification_logs_notification_channel_id_created_at_index')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->index(['notification_channel_id', 'created_at']);
            });
        }

        // Performance tests — composite [performance_monitor_id, created_at]
        if (! $this->indexExists('performance_tests', 'performance_tests_performance_monitor_id_created_at_index')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->index(['performance_monitor_id', 'created_at']);
            });
        }

        // Uptime checks — composite [monitor_id, checked_at, is_up]
        if (! $this->indexExists('uptime_checks', 'uptime_checks_monitor_id_checked_at_is_up_index')) {
            Schema::table('uptime_checks', function (Blueprint $table) {
                $table->index(['monitor_id', 'checked_at', 'is_up']);
            });
        }

        // Error logs — composite [site_id, last_seen_at]
        if (Schema::hasTable('error_logs') && ! $this->indexExists('error_logs', 'error_logs_site_id_last_seen_at_index')) {
            Schema::table('error_logs', function (Blueprint $table) {
                $table->index(['site_id', 'last_seen_at']);
            });
        }

        // Resource checks — composite [site_id, checked_at]
        if (Schema::hasTable('resource_checks') && ! $this->indexExists('resource_checks', 'resource_checks_site_id_checked_at_index')) {
            Schema::table('resource_checks', function (Blueprint $table) {
                $table->index(['site_id', 'checked_at']);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('backups', 'backups_status_created_at_index')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->dropIndex(['status', 'created_at']);
            });
        }

        if ($this->indexExists('notification_logs', 'notification_logs_notification_channel_id_created_at_index')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->dropIndex(['notification_channel_id', 'created_at']);
            });
        }

        if ($this->indexExists('performance_tests', 'performance_tests_performance_monitor_id_created_at_index')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->dropIndex(['performance_monitor_id', 'created_at']);
            });
        }

        if ($this->indexExists('uptime_checks', 'uptime_checks_monitor_id_checked_at_is_up_index')) {
            Schema::table('uptime_checks', function (Blueprint $table) {
                $table->dropIndex(['monitor_id', 'checked_at', 'is_up']);
            });
        }

        if (Schema::hasTable('error_logs') && $this->indexExists('error_logs', 'error_logs_site_id_last_seen_at_index')) {
            Schema::table('error_logs', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'last_seen_at']);
            });
        }

        if (Schema::hasTable('resource_checks') && $this->indexExists('resource_checks', 'resource_checks_site_id_checked_at_index')) {
            Schema::table('resource_checks', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'checked_at']);
            });
        }
    }
};
