<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 6 (SPEC §7.3) — the AwaitApproval route. A safe update the decision
 * engine held for a human is created but NOT dispatched; it is flagged with
 * approval_required = true and dispatched only once an operator approves it
 * (UpdatesOverview::approveUpdate). Defaults to false so every existing row and
 * the untouched (flag-off) flow behaves exactly as before.
 *
 * Expand-only + idempotent (ADD COLUMN IF NOT EXISTS), non-transactional to run
 * on the direct (non-PgBouncer) connection at deploy. Raw DDL (no Schema::)
 * because PgBouncer transaction pooling breaks the prepared statements
 * Schema::table emits (SQLSTATE 25P02).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE safe_updates ADD COLUMN IF NOT EXISTS approval_required boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE safe_updates DROP COLUMN IF EXISTS approval_required');
    }
};
