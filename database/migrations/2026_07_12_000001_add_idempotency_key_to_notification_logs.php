<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-20: SendNotificationJob retries created a fresh notification_logs row per
 * attempt, producing false "[ESCALATION] … Delivery FAILED" storms even when a
 * retry succeeded. The idempotency key is generated once per logical send (in the
 * job constructor) and preserved across retries, so the job upserts a single row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_logs', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->after('ack_token');
                $table->index('idempotency_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            if (Schema::hasColumn('notification_logs', 'idempotency_key')) {
                $table->dropIndex(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
