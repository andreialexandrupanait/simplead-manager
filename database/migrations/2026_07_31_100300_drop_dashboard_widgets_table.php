<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * dashboard_widgets was a true orphan: nothing wrote it, nothing read it, no
 * screen configured it, and production held zero rows. The only reference in the
 * whole codebase was a hasMany on User.
 *
 * The configurable-widget dashboard it belonged to was replaced by the SPEC §4.5
 * three-band Panou; the classic dashboard that survives (dashboard.classic) does
 * not use widgets either.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS dashboard_widgets');
    }

    public function down(): void
    {
        // Deliberately not recreated: the table carried no data and the feature it
        // served no longer exists. Recovering the definition means the squashed
        // schema in database/schema/pgsql-schema.sql.
    }
};
