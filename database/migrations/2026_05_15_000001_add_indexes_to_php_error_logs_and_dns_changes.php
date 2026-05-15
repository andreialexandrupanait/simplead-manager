<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('php_error_logs', function (Blueprint $table) {
            $table->index(['site_id', 'is_resolved', 'level'], 'php_error_logs_site_resolved_level_idx');
        });

        Schema::table('dns_changes', function (Blueprint $table) {
            $table->index('detected_at', 'dns_changes_detected_at_idx');
            $table->index('dns_monitor_id', 'dns_changes_dns_monitor_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('php_error_logs', function (Blueprint $table) {
            $table->dropIndex('php_error_logs_site_resolved_level_idx');
        });

        Schema::table('dns_changes', function (Blueprint $table) {
            $table->dropIndex('dns_changes_detected_at_idx');
            $table->dropIndex('dns_changes_dns_monitor_id_idx');
        });
    }
};
