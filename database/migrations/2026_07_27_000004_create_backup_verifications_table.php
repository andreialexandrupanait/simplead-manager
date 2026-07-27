<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 verification records for the V2 backup engine.
 *
 * A `backup_verifications` row is the evidence that a completed backup was
 * checked — either the cheap "create" verification produced the moment a backup
 * reaches `completed` (manifest whole, _COMPLETE present, every object present +
 * correct size, checksums.json consistent, composite checksum), or the expensive
 * sampled "deep" verification (archive opened, DB SQL parsed, full sha256
 * recomputed). A backup with no PASSED create-verification is NOT considered
 * "verified" for retention (ChainRetentionService keeps the last-verified chain
 * off `backup_sessions.verified_at`, which only a passing verification sets).
 *
 * Additive & fully reversible on V2's OWN tables (never touches the V1 `backups`
 * table). With config('backup_v2.*') flags off nothing ever writes here.
 *
 * PostgreSQL: jsonb columns (never json) per project convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_verifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('backup_session_id')
                ->constrained('backup_sessions')
                ->cascadeOnDelete();

            $table->string('kind');   // create | deep
            $table->string('status'); // passed | failed | corrupt

            $table->unsignedInteger('object_count')->default(0);
            $table->unsignedInteger('sample_size')->nullable(); // deep-verify only
            $table->string('composite_checksum')->nullable();

            $table->jsonb('checks')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['backup_session_id', 'kind']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_verifications');
    }
};
