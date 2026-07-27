<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 restore engine (simplead-backup) — explicit FSM session tracking.
 *
 * Additive & fully reversible. New table, no V1 mutation. Links to a V2
 * `backup_sessions` row (nullable) and/or a V1 `backups` row as the source.
 *
 * PostgreSQL: jsonb columns (never json) per project convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restore_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_session_id')->nullable()->constrained('backup_sessions')->nullOnDelete();
            // Source V1 backup row when restoring a legacy artifact.
            $table->foreignId('source_backup_id')->nullable()->constrained('backups')->nullOnDelete();

            $table->string('mode'); // safe_merge | mirror
            $table->jsonb('scope')->nullable();

            // FSM (App\Backup\V2\Enums\RestoreSessionState).
            $table->string('state');
            $table->string('stage')->nullable();

            // Resume / checkpoint bookkeeping.
            $table->jsonb('cursor')->nullable();
            $table->jsonb('checkpoint')->nullable();

            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();

            $table->string('idempotency_key')->unique();

            $table->string('error_code')->nullable(); // App\Backup\V2\Enums\BackupErrorCode
            $table->text('error_message')->nullable();

            // Rollback safety net.
            $table->jsonb('rollback_point')->nullable();
            $table->foreignId('pre_restore_backup_id')->nullable()->constrained('backups')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('site_id');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_sessions');
    }
};
