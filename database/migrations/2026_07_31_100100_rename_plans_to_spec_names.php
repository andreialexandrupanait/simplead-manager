<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §9: three plans, named Bază / Standard / Premium, ascending.
 *
 * The seeded names were Full Monitoring / Standard Maintenance / Basic, and the
 * order ran the wrong way — sort_order 1 was the richest plan, so the list read
 * from most to least. A rename in the seeder alone would not have reached
 * production, where the rows are editable from the UI and long since created.
 *
 * Matched by the old name only. A plan someone has already renamed by hand is
 * left exactly as it is: their name is a decision, not a default.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const RENAMES = [
        // old name => [new name, sort_order]
        'Basic' => ['Bază', 1],
        'Standard Maintenance' => ['Standard', 2],
        'Full Monitoring' => ['Premium', 3],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => [$new, $order]) {
            DB::table('maintenance_plans')
                ->where('name', $old)
                ->update(['name' => $new, 'sort_order' => $order]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => [$new, $order]) {
            DB::table('maintenance_plans')
                ->where('name', $new)
                ->update(['name' => $old, 'sort_order' => 4 - $order]);
        }
    }
};
