<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 2 — SPEC §12.3 report safety barriers. A report that trips a pre-send
 * barrier (unresolved critical incident, out-of-range uptime, …) is HELD instead
 * of auto-sent: these columns record why and when, so the held report surfaces
 * for manual review instead of leaving silently or going out with bad data.
 *
 * Expand-only + idempotent (IF NOT EXISTS), non-transactional to match the other
 * DDL migrations that run on the direct (non-PgBouncer) connection at deploy.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE reports ADD COLUMN IF NOT EXISTS send_held_reason text');
        DB::statement('ALTER TABLE reports ADD COLUMN IF NOT EXISTS send_held_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reports DROP COLUMN IF EXISTS send_held_reason');
        DB::statement('ALTER TABLE reports DROP COLUMN IF EXISTS send_held_at');
    }
};
