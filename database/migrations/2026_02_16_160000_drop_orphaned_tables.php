<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'links',
            'link_scans',
            'link_monitors',
            'seo_top_queries',
            'seo_top_pages',
            'seo_pinned_keywords',
            'seo_snapshots',
            'seo_alerts',
            'seo_configs',
            'woocommerce_alerts',
            'woocommerce_stats',
            'site_cron_jobs',
            'dns_records_cache',
            'blocked_requests',
            'ip_rules',
            'error_logs',
            'wp_audit_logs',
            'resource_checks',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Tables were unused dormant features — re-run original migrations to restore
    }
};
