<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links: faster filtering by scan + type + status
        if (Schema::hasTable('links') && ! $this->indexExists('links', 'idx_links_scan_type_status')) {
            Schema::table('links', function (Blueprint $table) {
                $table->index(['link_scan_id', 'type', 'status'], 'idx_links_scan_type_status');
            });
        }

        // Notification logs: faster cleanup and status queries
        if (Schema::hasTable('notification_logs') && ! $this->indexExists('notification_logs', 'idx_notification_logs_status_created')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'idx_notification_logs_status_created');
            });
        }

        // WP audit logs: faster paginated display (newest first)
        if (Schema::hasTable('wp_audit_logs') && ! $this->indexExists('wp_audit_logs', 'idx_wp_audit_logs_site_action_at')) {
            Schema::table('wp_audit_logs', function (Blueprint $table) {
                $table->index(['site_id', 'action_at'], 'idx_wp_audit_logs_site_action_at');
            });
        }

        // Performance tests: faster latest test lookup per site
        if (Schema::hasTable('performance_tests') && ! $this->indexExists('performance_tests', 'idx_performance_tests_site_device_latest')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->index(['site_id', 'device', 'tested_at'], 'idx_performance_tests_site_device_latest');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('links')) {
            Schema::table('links', function (Blueprint $table) {
                $table->dropIndex('idx_links_scan_type_status');
            });
        }

        if (Schema::hasTable('notification_logs')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->dropIndex('idx_notification_logs_status_created');
            });
        }

        if (Schema::hasTable('wp_audit_logs')) {
            Schema::table('wp_audit_logs', function (Blueprint $table) {
                $table->dropIndex('idx_wp_audit_logs_site_action_at');
            });
        }

        if (Schema::hasTable('performance_tests')) {
            Schema::table('performance_tests', function (Blueprint $table) {
                $table->dropIndex('idx_performance_tests_site_device_latest');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);
        return collect($indexes)->contains(fn ($idx) => $idx['name'] === $indexName);
    }
};
