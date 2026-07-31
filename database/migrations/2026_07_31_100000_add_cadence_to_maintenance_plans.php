<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §9 defines a plan by four things: uptime level, backup rhythm, active
 * checks, report cadence. Only two of them lived on the plan.
 *
 * Backup rhythm and report cadence were per-site only (backup_configs,
 * report_schedules), so a plan could turn backups on but not say how often —
 * which defeats the point of a reusable template across ~30 sites.
 *
 * Null means "leave the site's own setting alone", so applying a plan never
 * silently rewrites a deliberate per-site choice.
 *
 * Expand-only + idempotent, non-transactional so it runs on the direct connection
 * at deploy (Schema:: builders break under PgBouncer transaction pooling).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE maintenance_plans ADD COLUMN IF NOT EXISTS backup_frequency varchar(20)');
        DB::statement('ALTER TABLE maintenance_plans ADD COLUMN IF NOT EXISTS report_frequency varchar(20)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE maintenance_plans DROP COLUMN IF EXISTS backup_frequency');
        DB::statement('ALTER TABLE maintenance_plans DROP COLUMN IF EXISTS report_frequency');
    }
};
