<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-apply two columns whose original migrations (2026_07_10_000001 add_target_to
 * _safe_updates, 2026_07_10_000002 add_domain_breakers_to_site_health_state) were
 * recorded as "Ran" but never actually applied to production: they executed on
 * 2026-07-10 THROUGH PgBouncer (transaction pooling) before deploy.sh was fixed to
 * run migrations on the direct (pgsql_direct) connection. PgBouncer silently
 * dropped the DDL while the migrations-table INSERT committed, so the columns are
 * missing in prod even though the migrations show as run — SafeUpdate writes and
 * the Google/SEO domain circuit-breaker (CircuitBreakerService) both 500 in prod.
 *
 * Idempotent (ADD COLUMN IF NOT EXISTS) so it is a no-op anywhere the columns do
 * exist (test DB built from the schema dump + migrations, staging, etc.).
 * Single-statement + non-transactional to run cleanly on the direct connection.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // safe_updates.target — string nullable (orig: string()->nullable()->after('slug'))
        DB::statement('ALTER TABLE safe_updates ADD COLUMN IF NOT EXISTS target varchar(255) NULL');

        // site_health_state.domain_breakers — jsonb nullable (orig: jsonb()->nullable())
        DB::statement('ALTER TABLE site_health_state ADD COLUMN IF NOT EXISTS domain_breakers jsonb NULL');
    }

    public function down(): void
    {
        // No-op: dropping would re-break sites whose original migrations DID apply.
        // The columns are additive and nullable; leaving them is safe.
    }
};
