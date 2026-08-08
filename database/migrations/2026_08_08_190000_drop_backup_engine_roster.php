<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop `backup_configs.backup_engine` — the per-site roster that chose between two backup engines.
 *
 * With one engine there is nothing left to choose, and the column had stopped being inert: it
 * defaults to 'v1', and `InstallBackupPluginCommand` filtered the fleet on it. So a site connected
 * after the cutover would have been skipped by the engine installer in silence, and then shown a
 * backup screen that looked like every other one and never backed anything up. Two sites on this
 * fleet were already sitting in that state.
 *
 * `backups.engine` is deliberately NOT touched. That column labels which engine produced a given
 * historical row, which stays true and stays worth knowing. This one recorded an intention about the
 * future, and there is no longer a future in which it means anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE backup_configs DROP COLUMN IF EXISTS backup_engine');

        // And `backups.engine` now defaults to the engine that exists. The column stays — it is a
        // truthful label on the rows the old engine produced — but a DEFAULT of 'v1' means any row
        // inserted by something that does not set it explicitly gets stamped with an engine that has
        // been deleted. Every writer sets it today; the default is there for the one that forgets.
        DB::statement("ALTER TABLE backups ALTER COLUMN engine SET DEFAULT 'v2'");
    }

    public function down(): void
    {
        // Restored on the original default so a rollback lands on the same shape the column had
        // before it was dropped. Nothing reads it any more, so the value is a formality — but a
        // rollback that leaves a NOT NULL column absent would break the older code it exists for.
        DB::statement("ALTER TABLE backup_configs ADD COLUMN IF NOT EXISTS backup_engine varchar(8) NOT NULL DEFAULT 'v1'");
        DB::statement("ALTER TABLE backups ALTER COLUMN engine SET DEFAULT 'v1'");
    }
};
