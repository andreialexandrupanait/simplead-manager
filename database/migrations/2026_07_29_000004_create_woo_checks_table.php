<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 5.2 — WooCommerce store-health monitoring (conditional).
 *
 * One row per site holding the latest signals from the connector's /woo-health
 * endpoint. Only sites running WooCommerce are ever checked; a non-Woo site is
 * stored with applicable = false and never alerts.
 *
 * Expand-only + idempotent (CREATE TABLE IF NOT EXISTS), non-transactional to
 * match the other DDL migrations that run on the direct connection at deploy.
 * (Schema::create wraps in a transaction whose prepared statements break under
 * PgBouncer transaction pooling — P-25P02; raw DDL avoids it.)
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS woo_checks (
                id                      bigserial PRIMARY KEY,
                site_id                 bigint NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                applicable              boolean NOT NULL DEFAULT false,
                checkout_status         smallint,
                gateways_ok             boolean NOT NULL DEFAULT true,
                pending_orders_count    integer NOT NULL DEFAULT 0,
                pending_over_threshold  boolean NOT NULL DEFAULT false,
                discount_cron_scheduled boolean NOT NULL DEFAULT true,
                gateways                jsonb,
                last_error              varchar(255),
                checked_at              timestamptz,
                created_at              timestamptz,
                updated_at              timestamptz,
                CONSTRAINT woo_checks_site_id_unique UNIQUE (site_id)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS woo_checks');
    }
};
