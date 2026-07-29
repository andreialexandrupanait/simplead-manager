<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 7 (PROMPT-SAD-MANAGER §"Decizie pe modulele în observație") —
 * lightweight usage-analytics log for the four modules under observation
 * (Tweaks tabs, DatabaseCleanup, PluginConflict, Redirects). At 60 days we
 * decide keep/remove per module from the data:
 *
 *   SELECT module, count(*) FROM module_access_logs GROUP BY module;
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
            CREATE TABLE IF NOT EXISTS module_access_logs (
                id           bigserial PRIMARY KEY,
                module       varchar(64) NOT NULL,
                site_id      bigint REFERENCES sites(id) ON DELETE CASCADE,
                user_id      bigint,
                accessed_at  timestamptz NOT NULL,
                created_at   timestamptz
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS module_access_logs_module_accessed_at_index ON module_access_logs (module, accessed_at)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS module_access_logs');
    }
};
