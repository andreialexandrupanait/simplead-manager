<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §13 — mark when the "Standard SimpleAD" preset package was first applied
 * to a site, so SyncWordPressSite applies it exactly once (on first connect).
 *
 * Raw DDL + non-transactional to match the codebase convention (Schema::table
 * wraps in a transaction whose prepared statements break under PgBouncer
 * transaction pooling — SQLSTATE 25P02).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS standard_preset_applied_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS standard_preset_applied_at');
    }
};
