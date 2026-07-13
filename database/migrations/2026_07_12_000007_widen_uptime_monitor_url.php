<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * P3-17: uptime_monitors.url was varchar(255) while the monitor form
     * validates `max:2048`, so a URL between 256–2048 chars passed validation
     * and then 500'd on the INSERT. Widen the column to match the validation
     * ceiling.
     *
     * Single-statement DDL, expand-only, PgBouncer-direct-deploy safe.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE uptime_monitors ALTER COLUMN url TYPE varchar(2048)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE uptime_monitors ALTER COLUMN url TYPE varchar(255)');
    }
};
