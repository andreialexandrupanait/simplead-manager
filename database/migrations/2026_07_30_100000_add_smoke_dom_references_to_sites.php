<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §7.4 etapa 1 — the smoke check runs on 3-5 KEY URLs, not just the
 * homepage, and signal 4 compares each page's DOM node count against its own
 * previous measurement. A single integer (`sites.smoke_dom_reference`, added
 * for the homepage-only version) cannot hold that, so references move to a
 * jsonb map of url => node count. The old column stays as the homepage seed so
 * no already-captured baseline is lost.
 *
 * Expand-only + idempotent (IF NOT EXISTS), non-transactional so it runs on the
 * direct connection at deploy — Schema:: builders break under PgBouncer
 * transaction pooling (SQLSTATE 25P02).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS smoke_dom_references jsonb');
        // Per-update record of what the smoke check saw, alongside the existing
        // health_check_results / visual_regression_results columns.
        DB::statement('ALTER TABLE safe_updates ADD COLUMN IF NOT EXISTS smoke_check_results jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS smoke_dom_references');
        DB::statement('ALTER TABLE safe_updates DROP COLUMN IF EXISTS smoke_check_results');
    }
};
