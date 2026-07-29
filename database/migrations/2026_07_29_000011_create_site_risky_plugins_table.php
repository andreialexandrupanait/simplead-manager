<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 6 (SPEC §7.2/§7.3) — per-site risky-plugin list backing the update
 * decision engine. A plugin present here (is_risky = true) is routed to
 * AwaitApproval no matter how minor the bump.
 *
 * Expand-only + idempotent (CREATE TABLE / INDEX IF NOT EXISTS), non-
 * transactional to match the other DDL migrations that run on the direct
 * connection at deploy. (Schema::create wraps in a transaction whose prepared
 * statements break under PgBouncer transaction pooling — P-25P02; raw DDL
 * avoids it.)
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS site_risky_plugins (
                id          bigserial PRIMARY KEY,
                site_id     bigint NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                slug        varchar(255) NOT NULL,
                source      varchar(20) NOT NULL DEFAULT 'auto',
                reason      varchar(255),
                is_risky    boolean NOT NULL DEFAULT true,
                created_at  timestamptz,
                updated_at  timestamptz,
                CONSTRAINT site_risky_plugins_site_slug_unique UNIQUE (site_id, slug)
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS site_risky_plugins_site_id_index ON site_risky_plugins (site_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS site_risky_plugins');
    }
};
