<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sites table indexes
        Schema::table('sites', function (Blueprint $table) {
            $table->index('status');
            $table->index('health_score');
            $table->index(['health_score', 'is_up']);
        });

        // SSL certificates table
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->index('expires_at');
        });

        // Domain monitors table
        Schema::table('domain_monitors', function (Blueprint $table) {
            $table->index('expires_at');
        });

        // Uptime monitors table
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->index('current_state');
        });

        // WP audit logs table
        Schema::table('wp_audit_logs', function (Blueprint $table) {
            $table->index(['site_id', 'action_at']);
        });

        // Notification channels table
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->index(['is_active', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['health_score']);
            $table->dropIndex(['health_score', 'is_up']);
        });

        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
        });

        Schema::table('domain_monitors', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
        });

        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->dropIndex(['current_state']);
        });

        Schema::table('wp_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'action_at']);
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'is_default']);
        });
    }
};
