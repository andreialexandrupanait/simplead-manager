<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §9 lists "adresă de test formulare" as one of the four per-site profile
 * fields. It was the only one of the four with nowhere to live at all — the form
 * checker had no address concept, and the connector synthesised one locally.
 *
 * Null means "use the global config('monitoring.form_test.deliver_to')".
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct connection
 * at deploy (Schema:: builders break under PgBouncer transaction pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS form_test_email varchar(191)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS form_test_email');
    }
};
