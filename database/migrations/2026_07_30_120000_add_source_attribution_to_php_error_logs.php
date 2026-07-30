<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §5.6 — "Atribuirea se face din calea fișierului: /wp-content/plugins/{slug}/
 * sau /themes/{slug}/, potrivit cu inventarul."
 *
 * Without these two columns an aggregated error is just a message and a path;
 * with them the report can say WHICH plugin produced 847 warnings, which is the
 * whole point of §12.4's "cifra care justifică factura".
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct
 * connection at deploy (Schema:: builders break under PgBouncer pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE php_error_logs ADD COLUMN IF NOT EXISTS source_type varchar(20) DEFAULT 'unknown'");
        DB::statement('ALTER TABLE php_error_logs ADD COLUMN IF NOT EXISTS source_slug varchar(191)');
        DB::statement('CREATE INDEX IF NOT EXISTS php_error_logs_source_idx ON php_error_logs (site_id, source_type, source_slug)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS php_error_logs_source_idx');
        DB::statement('ALTER TABLE php_error_logs DROP COLUMN IF EXISTS source_type');
        DB::statement('ALTER TABLE php_error_logs DROP COLUMN IF EXISTS source_slug');
    }
};
