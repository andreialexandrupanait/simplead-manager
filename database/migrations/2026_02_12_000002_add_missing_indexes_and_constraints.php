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
        // Add missing indexes on sites table
        Schema::table('sites', function (Blueprint $table) {
            if (! $this->indexExists('sites', 'sites_is_up_index')) {
                $table->index('is_up');
            }
            if (! $this->indexExists('sites', 'sites_client_id_index')) {
                $table->index('client_id');
            }
            if (! $this->indexExists('sites', 'sites_user_id_index')) {
                $table->index('user_id');
            }
        });

        // Add missing indexes on backups table
        if (! $this->indexExists('backups', 'backups_site_id_status_index')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->index(['site_id', 'status']);
            });
        }

        // Add index on error_logs
        if (Schema::hasTable('error_logs') && ! $this->indexExists('error_logs', 'error_logs_site_id_created_at_index')) {
            Schema::table('error_logs', function (Blueprint $table) {
                $table->index(['site_id', 'created_at']);
            });
        }

        // Add index on security_scans
        if (! $this->indexExists('security_scans', 'security_scans_site_id_scanned_at_index')) {
            Schema::table('security_scans', function (Blueprint $table) {
                $table->index(['site_id', 'scanned_at']);
            });
        }

        // Add index on vulnerability_alerts
        if (Schema::hasTable('vulnerability_alerts') && ! $this->indexExists('vulnerability_alerts', 'vulnerability_alerts_site_id_status_index')) {
            Schema::table('vulnerability_alerts', function (Blueprint $table) {
                $table->index(['site_id', 'status']);
            });
        }

        // Add index on database_health_checks
        if (! $this->indexExists('database_health_checks', 'database_health_checks_site_id_checked_at_index')) {
            Schema::table('database_health_checks', function (Blueprint $table) {
                $table->index(['site_id', 'checked_at']);
            });
        }

        // Add index on reports
        if (Schema::hasTable('reports') && ! $this->indexExists('reports', 'reports_site_id_created_at_index')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->index(['site_id', 'created_at']);
            });
        }

        // Add index on uptime_checks for query performance
        if (! $this->indexExists('uptime_checks', 'uptime_checks_monitor_id_checked_at_index')) {
            Schema::table('uptime_checks', function (Blueprint $table) {
                $table->index(['monitor_id', 'checked_at']);
            });
        }

        // Add index on cloudflare_connections
        if (Schema::hasTable('cloudflare_connections') && ! $this->indexExists('cloudflare_connections', 'cloudflare_connections_user_id_index')) {
            Schema::table('cloudflare_connections', function (Blueprint $table) {
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if ($this->indexExists('sites', 'sites_is_up_index')) {
                $table->dropIndex(['is_up']);
            }
            if ($this->indexExists('sites', 'sites_client_id_index')) {
                $table->dropIndex(['client_id']);
            }
            if ($this->indexExists('sites', 'sites_user_id_index')) {
                $table->dropIndex(['user_id']);
            }
        });

        if ($this->indexExists('backups', 'backups_site_id_status_index')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'status']);
            });
        }

        if (Schema::hasTable('error_logs') && $this->indexExists('error_logs', 'error_logs_site_id_created_at_index')) {
            Schema::table('error_logs', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'created_at']);
            });
        }

        if ($this->indexExists('security_scans', 'security_scans_site_id_scanned_at_index')) {
            Schema::table('security_scans', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'scanned_at']);
            });
        }

        if (Schema::hasTable('vulnerability_alerts') && $this->indexExists('vulnerability_alerts', 'vulnerability_alerts_site_id_status_index')) {
            Schema::table('vulnerability_alerts', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'status']);
            });
        }

        if ($this->indexExists('database_health_checks', 'database_health_checks_site_id_checked_at_index')) {
            Schema::table('database_health_checks', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'checked_at']);
            });
        }

        if (Schema::hasTable('reports') && $this->indexExists('reports', 'reports_site_id_created_at_index')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'created_at']);
            });
        }

        if ($this->indexExists('uptime_checks', 'uptime_checks_monitor_id_checked_at_index')) {
            Schema::table('uptime_checks', function (Blueprint $table) {
                $table->dropIndex(['monitor_id', 'checked_at']);
            });
        }

        if (Schema::hasTable('cloudflare_connections') && $this->indexExists('cloudflare_connections', 'cloudflare_connections_user_id_index')) {
            Schema::table('cloudflare_connections', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        }
    }
};
