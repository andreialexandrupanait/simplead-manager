<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-46: make audit-trail ingestion idempotent.
 *
 * The pull job's `since` cursor is inclusive on some connectors, and retries /
 * overlapping runs could re-fetch and re-insert the same event, so the table
 * could accumulate duplicate audit rows. This adds a deterministic content hash
 * and a UNIQUE (site_id, dedup_hash) index so re-ingestion becomes a no-op
 * (SecurityActivityService uses insertOrIgnore against it).
 *
 * Expand-only, single-statement-safe DDL for the PgBouncer-direct deploy:
 *   1. add the nullable column,
 *   2. backfill hashes for existing rows,
 *   3. delete pre-existing duplicates (keep the lowest id),
 *   4. add the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('security_activity_logs', 'dedup_hash')) {
            Schema::table('security_activity_logs', function (Blueprint $table) {
                $table->char('dedup_hash', 32)->nullable()->after('created_at');
            });
        }

        // Backfill existing rows using the SAME field order as
        // SecurityActivityService::dedupHash(). occurred_at is a naive UTC
        // timestamp; format it identically to the PHP 'Y-m-d H:i:s'.
        DB::statement(<<<'SQL'
            UPDATE security_activity_logs
            SET dedup_hash = md5(
                site_id::text || '|' ||
                event_type || '|' ||
                coalesce(username, '') || '|' ||
                coalesce(object_type, '') || '|' ||
                coalesce(object_name, '') || '|' ||
                coalesce(action, '') || '|' ||
                coalesce(host(ip_address), '') || '|' ||
                to_char(occurred_at, 'YYYY-MM-DD HH24:MI:SS')
            )
            WHERE dedup_hash IS NULL
        SQL);

        // Drop pre-existing duplicates, keeping the earliest-inserted row.
        DB::statement(<<<'SQL'
            DELETE FROM security_activity_logs a
            USING security_activity_logs b
            WHERE a.dedup_hash = b.dedup_hash
              AND a.site_id = b.site_id
              AND a.id > b.id
        SQL);

        if (! $this->indexExists('security_activity_logs_site_dedup_unique')) {
            DB::statement('CREATE UNIQUE INDEX security_activity_logs_site_dedup_unique ON security_activity_logs (site_id, dedup_hash)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS security_activity_logs_site_dedup_unique');

        if (Schema::hasColumn('security_activity_logs', 'dedup_hash')) {
            Schema::table('security_activity_logs', function (Blueprint $table) {
                $table->dropColumn('dedup_hash');
            });
        }
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$name]) !== null;
    }
};
