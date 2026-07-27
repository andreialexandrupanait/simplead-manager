<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 read-only index of LEGACY backups (v2-zip sidecar + multipart-v3 manifest,
 * plus orphaned objects and phantom rows) for the V2 engine.
 *
 * This table is a pure INDEX/CATALOGUE. Importing a legacy backup creates a row
 * here describing where the artifact lives and how it classifies (per
 * docs/backup/LEGACY-BACKUPS-DECISION.md categories A–F) — it NEVER moves,
 * rewrites or deletes the legacy objects themselves. Actually restoring a legacy
 * artifact stays gated behind config('backup_v2.legacy_restore_enabled').
 *
 * Additive & fully reversible; touches no existing table's rows. With
 * config('backup_v2.*') flags off nothing ever writes here.
 *
 * PostgreSQL: jsonb columns (never json) per project convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_backup_index', function (Blueprint $table) {
            $table->id();

            // Best-effort links back to the existing records (all nullable — an
            // orphaned object has no backups row; a phantom row has no object).
            $table->foreignId('backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->foreignId('storage_destination_id')->nullable()->constrained('storage_destinations')->nullOnDelete();
            // Plain nullable integer (NOT a FK): a legacy orphan's descriptor may
            // reference a site that no longer exists — the index must still record it.
            $table->unsignedBigInteger('site_id')->nullable();

            $table->string('format')->nullable();          // multipart-v3 | v2-zip | unknown
            $table->string('classification');              // valid | verification_required | recoverable | incomplete | orphaned | phantom | quarantined | invalid | unknown
            $table->string('path')->nullable();            // storage path of the metadata doc / archive
            $table->boolean('object_present')->default(false);

            $table->string('composite_checksum')->nullable();
            $table->unsignedBigInteger('total_size')->default(0);
            $table->unsignedInteger('file_count')->default(0);

            $table->jsonb('metadata')->nullable(); // normalised descriptor + reasons
            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();

            $table->unique(['storage_destination_id', 'path'], 'legacy_backup_index_dest_path_unique');
            $table->index('classification');
            $table->index('backup_id');
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_backup_index');
    }
};
