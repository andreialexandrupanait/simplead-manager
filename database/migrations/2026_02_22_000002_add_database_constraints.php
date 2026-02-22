<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backup status CHECK constraint
        DB::statement("ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_status_check");
        DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('pending', 'in_progress', 'completed', 'failed'))");

        // Backup restore_status CHECK constraint
        DB::statement("ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_restore_status_check");
        DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_restore_status_check CHECK (restore_status IS NULL OR restore_status IN ('pending', 'in_progress', 'completed', 'failed'))");

        // SSL certificate status CHECK constraint
        DB::statement("ALTER TABLE ssl_certificates DROP CONSTRAINT IF EXISTS ssl_certificates_status_check");
        DB::statement("ALTER TABLE ssl_certificates ADD CONSTRAINT ssl_certificates_status_check CHECK (status IN ('pending', 'valid', 'expiring_soon', 'expired', 'error'))");

        // Uptime monitor status CHECK constraint
        DB::statement("ALTER TABLE uptime_monitors DROP CONSTRAINT IF EXISTS uptime_monitors_status_check");
        DB::statement("ALTER TABLE uptime_monitors ADD CONSTRAINT uptime_monitors_status_check CHECK (status IN ('active', 'paused'))");

        // Uptime monitor current_state CHECK constraint
        DB::statement("ALTER TABLE uptime_monitors DROP CONSTRAINT IF EXISTS uptime_monitors_current_state_check");
        DB::statement("ALTER TABLE uptime_monitors ADD CONSTRAINT uptime_monitors_current_state_check CHECK (current_state IN ('up', 'down', 'degraded', 'unknown'))");

        // Report status CHECK constraint
        DB::statement("ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check");
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('pending', 'generating', 'completed', 'failed'))");

        // Activity logs: require at least site_id or user_id
        DB::statement("ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_has_context");
        DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_has_context CHECK (site_id IS NOT NULL OR user_id IS NOT NULL)");

        // Foreign key for applied_preset_id on sites (skip if already exists)
        if (Schema::hasColumn('sites', 'applied_preset_id')) {
            $exists = DB::select("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'sites_applied_preset_id_foreign'
                AND table_name = 'sites'
            ");
            if (empty($exists)) {
                Schema::table('sites', function (Blueprint $table) {
                    $table->foreign('applied_preset_id')
                        ->references('id')
                        ->on('site_presets')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_status_check");
        DB::statement("ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_restore_status_check");
        DB::statement("ALTER TABLE ssl_certificates DROP CONSTRAINT IF EXISTS ssl_certificates_status_check");
        DB::statement("ALTER TABLE uptime_monitors DROP CONSTRAINT IF EXISTS uptime_monitors_status_check");
        DB::statement("ALTER TABLE uptime_monitors DROP CONSTRAINT IF EXISTS uptime_monitors_current_state_check");
        DB::statement("ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check");
        DB::statement("ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_has_context");

        if (Schema::hasColumn('sites', 'applied_preset_id')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropForeign(['applied_preset_id']);
            });
        }
    }
};
