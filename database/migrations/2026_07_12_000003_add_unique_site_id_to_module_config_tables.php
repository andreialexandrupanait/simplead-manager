<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-37: the per-site module config tables materialized from a maintenance plan
 * are one-row-per-site by design, but performance_monitors / uptime_monitors /
 * backup_configs lacked a unique(site_id) constraint. The check-then-insert in
 * ModuleConfigService could therefore race two concurrent applies into duplicate
 * config rows. Dedupe (keep the newest row per site) then add the constraint so
 * the DB enforces the invariant the app now relies on via updateOrCreate.
 *
 * Expand-only + single-statement-safe: each DELETE and each ALTER is its own
 * statement, so it runs cleanly on the direct connection under PgBouncer.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * @var array<int, string>
     */
    private array $tables = [
        'performance_monitors',
        'uptime_monitors',
        'backup_configs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            // Keep the most recent row (highest id) per site; drop older duplicates.
            DB::statement(<<<SQL
                DELETE FROM {$table}
                WHERE id NOT IN (
                    SELECT max_id FROM (
                        SELECT MAX(id) AS max_id
                        FROM {$table}
                        GROUP BY site_id
                    ) AS keep
                )
            SQL);

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unique('site_id', "{$table}_site_id_unique");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_site_id_unique");
            });
        }
    }
};
