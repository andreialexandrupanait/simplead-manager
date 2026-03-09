<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standalone event_type index for cross-site activity log filtering
        Schema::table('security_activity_logs', function (Blueprint $table) {
            $table->index('event_type', 'security_activity_logs_event_type_index');
        });

        // Standalone severity index for dashboard sorting/filtering across sites
        Schema::table('vulnerability_alerts', function (Blueprint $table) {
            $table->index('severity', 'vulnerability_alerts_severity_index');
        });

        // Status + picked_up_at for stale command cleanup (scopeStale queries without site_id)
        Schema::table('security_commands', function (Blueprint $table) {
            $table->index(['status', 'picked_up_at'], 'security_commands_status_picked_up_index');
        });
    }

    public function down(): void
    {
        Schema::table('security_activity_logs', function (Blueprint $table) {
            $table->dropIndex('security_activity_logs_event_type_index');
        });

        Schema::table('vulnerability_alerts', function (Blueprint $table) {
            $table->dropIndex('vulnerability_alerts_severity_index');
        });

        Schema::table('security_commands', function (Blueprint $table) {
            $table->dropIndex('security_commands_status_picked_up_index');
        });
    }
};
