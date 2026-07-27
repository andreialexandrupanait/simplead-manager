<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 backup engine (simplead-backup) — explicit FSM session tracking.
 *
 * Additive & fully reversible: a NEW table that does not touch the V1 `backups`
 * table. `backup_id` links back to a V1 `backups` row when one exists so the two
 * engines can coexist during migration. With config('backup_v2.*') flags off,
 * nothing ever writes here.
 *
 * PostgreSQL: jsonb columns (never json) per project convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Link to the V1 backups row (nullable — set once a V1 artifact exists).
            $table->foreignId('backup_id')->nullable()->constrained('backups')->nullOnDelete();

            $table->string('type'); // full | incremental | database | files
            $table->jsonb('scope')->nullable();
            $table->jsonb('exclusions')->nullable();
            $table->string('exclusion_policy_hash')->nullable();
            $table->string('scope_hash')->nullable();
            $table->string('resource_profile'); // low_impact | normal | fast

            // FSM (App\Backup\V2\Enums\BackupSessionState).
            $table->string('state');
            $table->string('stage')->nullable();

            // Resume / checkpoint bookkeeping.
            $table->jsonb('cursor')->nullable();
            $table->jsonb('checkpoint')->nullable();
            $table->jsonb('confirmed_objects')->nullable();
            $table->jsonb('confirmed_parts')->nullable();

            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();

            $table->string('idempotency_key')->unique();

            $table->string('error_code')->nullable(); // App\Backup\V2\Enums\BackupErrorCode
            $table->text('error_message')->nullable();

            $table->string('format_version');

            // Incremental chain (self-reference to the full base session).
            $table->foreignId('full_base_id')->nullable()->constrained('backup_sessions')->nullOnDelete();
            $table->integer('chain_position')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('site_id');
            $table->index('state');
            $table->index('exclusion_policy_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_sessions');
    }
};
