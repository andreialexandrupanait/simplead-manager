<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The weekly contact-form test shipped covering every connected site, gated only
 * by the plugin fail-safe. That gate is correct but it is a consequence, not a
 * choice — nobody had said "test this site". Opt-in per site, default OFF, so the
 * sweep touches nothing until it is switched on deliberately.
 *
 * form_test_form_id targets a specific form: the connector otherwise submits to
 * the first one it finds, which on a site with both a newsletter and a contact
 * form can be the wrong one. Null keeps the first-form behaviour.
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct connection
 * at deploy (Schema:: builders break under PgBouncer transaction pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS form_test_enabled boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS form_test_form_id varchar(64)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS form_test_enabled');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS form_test_form_id');
    }
};
