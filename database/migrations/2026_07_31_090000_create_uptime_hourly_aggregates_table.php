<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §14.1: raw uptime pings are kept 14 days, hourly aggregates 13 months.
 *
 * The 13-month row is what makes "July this year against July last year" possible.
 * Until now only monthly snapshots existed, so raw pings had to be retained far
 * beyond the spec's 14 days for any window longer than the retention period to
 * mean anything — and even then the long window silently covered only whatever
 * had not yet been pruned.
 *
 * One row per monitor per hour. p95 is stored alongside the average because an
 * average response time hides exactly the spikes a client notices.
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
            CREATE TABLE IF NOT EXISTS uptime_hourly_aggregates (
                id                    bigserial PRIMARY KEY,
                monitor_id            bigint NOT NULL REFERENCES uptime_monitors(id) ON DELETE CASCADE,
                site_id               bigint REFERENCES sites(id) ON DELETE CASCADE,
                bucket_hour           timestamptz NOT NULL,
                checks_total          integer NOT NULL DEFAULT 0,
                checks_up             integer NOT NULL DEFAULT 0,
                uptime_pct            numeric(6,3),
                avg_response_time     integer,
                p95_response_time     integer,
                max_response_time     integer,
                created_at            timestamptz,
                updated_at            timestamptz
            )
        SQL);

        // The aggregator upserts on this pair, so it has to be unique: a rerun for
        // an hour must correct that hour rather than double it.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uptime_hourly_aggregates_monitor_bucket_unique ON uptime_hourly_aggregates (monitor_id, bucket_hour)');
        DB::statement('CREATE INDEX IF NOT EXISTS uptime_hourly_aggregates_site_bucket_index ON uptime_hourly_aggregates (site_id, bucket_hour)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS uptime_hourly_aggregates');
    }
};
