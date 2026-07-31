<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which engine produced a backup, and which engine a site should use.
 *
 * The new engine is about to start materialising rows in `backups` so the whole
 * V1 surface — the interface, the observer that moves sites.last_backup_at, the
 * alerts, the health score, chain retention — works over it unchanged. That row
 * is not a display artifact. It is a handle six V1 code paths reach for, several
 * of them destructively and with nobody clicking anything:
 *
 *  - retention would take the single-file delete branch and call DeleteObject on
 *    a prefix. S3 answers 204 for a key that does not exist, so it would read as
 *    success, drop the row, and orphan every object under that prefix forever;
 *  - stuck recovery would see a legitimately long run as dead and dispatch a V1
 *    backup on top of a live session;
 *  - the weekly restore-verification would download `file_path`, fail, and mark
 *    a byte-for-byte verified backup as verification-failed;
 *  - restore and download would follow a format branch that does not fit.
 *
 * So the discriminator lands, and every one of those paths learns to honour it,
 * BEFORE the first such row exists. This migration is deliberately inert: after
 * it runs, every row and every site still says 'v1' and no code reads either
 * column yet.
 *
 * `backup_configs.backup_engine` is the per-site roster. It says what a site
 * should use; BackupV2Gate still says what it may use, and permission is checked
 * last — so removing a site from the env allowlist reverts it regardless of the
 * column, which is the one-line rollback a pilot needs.
 *
 * Both columns are scaffolding with a demolition date: once every site is on the
 * new engine and the old one is deleted, there is no second engine to tell apart
 * and the columns come out again.
 *
 * NOT NULL DEFAULT is metadata-only on PostgreSQL 11+, so neither statement
 * rewrites the table. Raw DDL + non-transactional so it runs on the direct
 * connection at deploy — Schema:: builders break under PgBouncer transaction
 * pooling. Both are ALTERs on live tables, so recycle PgBouncer afterwards or
 * stale prepared plans give intermittent 500s.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE backups ADD COLUMN IF NOT EXISTS engine varchar(8) NOT NULL DEFAULT 'v1'");
        DB::statement("ALTER TABLE backup_configs ADD COLUMN IF NOT EXISTS backup_engine varchar(8) NOT NULL DEFAULT 'v1'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backups DROP COLUMN IF EXISTS engine');
        DB::statement('ALTER TABLE backup_configs DROP COLUMN IF EXISTS backup_engine');
    }
};
