<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §14.1: raw PHP errors are kept 30 days, aggregated errors 13 months.
 *
 * The raw table is already deduplicated by signature, but its rows still age out
 * with the raw retention, which would take the year-on-year history with them.
 * This table keeps one row per site per month per source (plugin/theme/core), so
 * §12.4's sentence — "847 warnings from JetWooBuilder 2.1.3, fixed on 18 July,
 * active errors now: 0" — can still be written about a month whose raw rows are
 * long gone.
 *
 * Expand-only + idempotent raw DDL, non-transactional: Schema::create wraps in a
 * transaction whose prepared statements break under PgBouncer transaction pooling.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS php_error_monthly_aggregates (
                id             bigserial PRIMARY KEY,
                site_id        bigint NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                bucket_month   date NOT NULL,
                source_type    varchar(20) NOT NULL DEFAULT 'unknown',
                -- Empty string, not NULL: this is part of the unique key below, and
                -- NULL never equals NULL, so nullable would defeat the upsert.
                source_slug    varchar(191) NOT NULL DEFAULT '',
                level          varchar(32) NOT NULL DEFAULT 'unknown',
                signatures     integer NOT NULL DEFAULT 0,
                occurrences    integer NOT NULL DEFAULT 0,
                resolved_count integer NOT NULL DEFAULT 0,
                created_at     timestamptz,
                updated_at     timestamptz
            )
        SQL);

        // The aggregator upserts on this tuple, so re-running a month corrects it
        // instead of doubling it.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS php_error_monthly_aggregates_bucket_unique
            ON php_error_monthly_aggregates (site_id, bucket_month, source_type, source_slug, level)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS php_error_monthly_aggregates');
    }
};
