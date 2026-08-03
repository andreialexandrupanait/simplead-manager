<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which plugin the chosen form belongs to. Discovery has always returned it per
 * form, and the Manager has always thrown it away — so the site re-derived the
 * plugin from whatever was active, and on a site running Mailchimp next to
 * Elementor Pro it picked Mailchimp and reported a passing test for a contact
 * form nobody had submitted.
 *
 * Null keeps the old behaviour: the site decides for itself.
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct
 * connection at deploy (Schema:: builders break under PgBouncer pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS form_test_plugin varchar(32)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS form_test_plugin');
    }
};
