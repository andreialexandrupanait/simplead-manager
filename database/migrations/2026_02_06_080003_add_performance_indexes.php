<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check if an index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        ) !== null;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sites table indexes
        Schema::table('sites', function (Blueprint $table) {
            if (!$this->indexExists('sites', 'sites_status_index')) {
                $table->index('status');
            }
            if (!$this->indexExists('sites', 'sites_health_score_index')) {
                $table->index('health_score');
            }
            if (!$this->indexExists('sites', 'sites_health_score_is_up_index')) {
                $table->index(['health_score', 'is_up']);
            }
        });

        // SSL certificates table
        if (!$this->indexExists('ssl_certificates', 'ssl_certificates_expires_at_index')) {
            Schema::table('ssl_certificates', function (Blueprint $table) {
                $table->index('expires_at');
            });
        }

        // Domain monitors table
        if (!$this->indexExists('domain_monitors', 'domain_monitors_expires_at_index')) {
            Schema::table('domain_monitors', function (Blueprint $table) {
                $table->index('expires_at');
            });
        }

        // Uptime monitors table
        if (!$this->indexExists('uptime_monitors', 'uptime_monitors_current_state_index')) {
            Schema::table('uptime_monitors', function (Blueprint $table) {
                $table->index('current_state');
            });
        }

        // WP audit logs table
        if (!$this->indexExists('wp_audit_logs', 'wp_audit_logs_site_id_action_at_index')) {
            Schema::table('wp_audit_logs', function (Blueprint $table) {
                $table->index(['site_id', 'action_at']);
            });
        }

        // Notification channels table
        if (!$this->indexExists('notification_channels', 'notification_channels_is_active_is_default_index')) {
            Schema::table('notification_channels', function (Blueprint $table) {
                $table->index(['is_active', 'is_default']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if ($this->indexExists('sites', 'sites_status_index')) {
                $table->dropIndex(['status']);
            }
            if ($this->indexExists('sites', 'sites_health_score_index')) {
                $table->dropIndex(['health_score']);
            }
            if ($this->indexExists('sites', 'sites_health_score_is_up_index')) {
                $table->dropIndex(['health_score', 'is_up']);
            }
        });

        if ($this->indexExists('ssl_certificates', 'ssl_certificates_expires_at_index')) {
            Schema::table('ssl_certificates', function (Blueprint $table) {
                $table->dropIndex(['expires_at']);
            });
        }

        if ($this->indexExists('domain_monitors', 'domain_monitors_expires_at_index')) {
            Schema::table('domain_monitors', function (Blueprint $table) {
                $table->dropIndex(['expires_at']);
            });
        }

        if ($this->indexExists('uptime_monitors', 'uptime_monitors_current_state_index')) {
            Schema::table('uptime_monitors', function (Blueprint $table) {
                $table->dropIndex(['current_state']);
            });
        }

        if ($this->indexExists('wp_audit_logs', 'wp_audit_logs_site_id_action_at_index')) {
            Schema::table('wp_audit_logs', function (Blueprint $table) {
                $table->dropIndex(['site_id', 'action_at']);
            });
        }

        if ($this->indexExists('notification_channels', 'notification_channels_is_active_is_default_index')) {
            Schema::table('notification_channels', function (Blueprint $table) {
                $table->dropIndex(['is_active', 'is_default']);
            });
        }
    }
};
