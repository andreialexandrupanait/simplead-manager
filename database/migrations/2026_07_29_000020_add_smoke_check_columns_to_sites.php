<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 6, SPEC §7.4 etapa 1 — persist per-site smoke-check configuration/state:
 *   - smoke_canary_selector: an operator-chosen HTML fragment (id/class/markup)
 *     that must be present in the homepage after an update; null uses the '<body'
 *     fallback.
 *   - smoke_dom_reference: last measured DOM node count (approx), used as the
 *     baseline the post-update smoke check compares against within ±20%.
 *
 * Expand-only + idempotent (IF NOT EXISTS), non-transactional to run on the
 * direct (non-PgBouncer) connection at deploy — matching the other DDL migrations.
 * Raw DDL (no Schema::) because PgBouncer transaction pooling breaks the prepared
 * statements Schema::table emits (SQLSTATE 25P02).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS smoke_canary_selector varchar(255)');
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS smoke_dom_reference integer');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS smoke_canary_selector');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS smoke_dom_reference');
    }
};
