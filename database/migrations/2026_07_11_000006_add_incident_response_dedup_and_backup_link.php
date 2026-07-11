<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand-only, nullable columns for two P0 fixes on the incident-response module:
 *
 *  - backup_id (P0-20): links an incident to the completed+verified Backup row that
 *    actually satisfied the "backup before destructive action" invariant, so the
 *    backup_created flag can never be a lie about a backup that was never taken.
 *  - response_attempted_at + acknowledged_at (P0-21): let the dispatcher suppress
 *    re-dispatch of escalated (unacknowledged) incidents indefinitely and apply an
 *    exponential backoff to persistently-failing triggers, instead of re-running the
 *    full AI pipeline every cooldown window forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_responses', function (Blueprint $table) {
            if (! Schema::hasColumn('incident_responses', 'backup_id')) {
                // No FK constraint on purpose: retention deletes backups, and this is
                // an audit pointer, not an ownership edge — a dangling id is acceptable.
                $table->unsignedBigInteger('backup_id')->nullable()->after('backup_created');
            }
            if (! Schema::hasColumn('incident_responses', 'response_attempted_at')) {
                $table->timestamp('response_attempted_at')->nullable()->after('escalated_at');
            }
            if (! Schema::hasColumn('incident_responses', 'acknowledged_at')) {
                $table->timestamp('acknowledged_at')->nullable()->after('response_attempted_at');
            }
        });

        Schema::table('incident_responses', function (Blueprint $table) {
            // Supports the dispatcher's per-(site,trigger) suppression lookups.
            $table->index(['site_id', 'trigger_type', 'status'], 'incident_responses_site_trigger_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incident_responses', function (Blueprint $table) {
            $table->dropIndex('incident_responses_site_trigger_status_idx');
            $table->dropColumn(['backup_id', 'response_attempted_at', 'acknowledged_at']);
        });
    }
};
