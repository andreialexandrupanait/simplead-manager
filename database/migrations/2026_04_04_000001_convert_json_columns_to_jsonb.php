<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns that should be jsonb but were created as json.
     * Format: 'table_name' => ['column1', 'column2', ...]
     */
    private array $columnsToConvert = [
        'activity_logs' => ['metadata'],
        'analytics_cache' => ['data'],
        'app_backup_configs' => ['components'],
        'app_backups' => ['components', 'component_sizes', 'log'],
        'cloudflare_cache_purges' => ['targets'],
        'core_file_checks' => ['modified_files', 'missing_files', 'unknown_files'],
        'dashboard_widgets' => ['config'],
        'database_cleanup_configs' => ['auto_clean_types'],
        'database_health_checks' => ['tables_data', 'largest_tables', 'tables_with_overhead'],
        'dns_records_cache' => ['a_records', 'aaaa_records', 'cname_records', 'mx_records', 'txt_records', 'ns_records', 'soa_record'],
        'email_health_checks' => ['spf_issues', 'blacklists_checked', 'mx_records'],
        'error_logs' => ['context'],
        'google_connections' => ['scopes'],
        'link_monitors' => ['exclude_paths', 'exclude_domains'],
        'notification_channels' => ['event_subscriptions'],
        'notification_logs' => ['metadata'],
        'performance_monitors' => ['budgets'],
        'performance_tests' => ['opportunities', 'diagnostics', 'third_party_scripts', 'unused_js_details', 'unused_css_details', 'image_audit', 'wp_health_checks', 'filmstrip'],
        'report_schedules' => ['recipient_emails'],
        'report_templates' => ['sections', 'section_overrides', 'section_options'],
        'reports' => ['sent_to', 'data_snapshot'],
        'safe_updates' => ['health_check_results'],
        'search_console_cache' => ['data'],
        'security_scans' => ['scores_breakdown'],
        'site_cron_jobs' => ['arguments'],
        'site_presets' => ['modules'],
        'ssl_certificates' => ['san_domains'],
        'storage_destinations' => ['config'],
        'uptime_incidents' => ['notified_via'],
        'uptime_monitors' => ['http_headers', 'accepted_status_codes', 'alert_contacts'],
        'vulnerability_alerts' => ['references'],
        'wp_audit_logs' => ['old_value', 'new_value'],
    ];

    public function up(): void
    {
        foreach ($this->columnsToConvert as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE jsonb USING \"{$column}\"::jsonb");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columnsToConvert as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE json USING \"{$column}\"::json");
            }
        }
    }
};
