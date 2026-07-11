<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dns_monitors', 'pending_records')) {
            // Holds a candidate DNS observation that differs from the confirmed
            // state. A change is only committed once the same candidate is seen
            // on two consecutive checks, so a single transient resolver blip can
            // never produce a false "records deleted" alert.
            DB::statement('ALTER TABLE dns_monitors ADD COLUMN pending_records jsonb NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dns_monitors DROP COLUMN IF EXISTS pending_records');
    }
};
