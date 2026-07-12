<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2-42: a failing DNS check left no visible state — no error, no counter,
     * no notification — so a broken DNS monitor was invisible. Add a persisted,
     * queryable error state mirroring how uptime failures are recorded.
     *
     * Single-statement DDL each (PgBouncer-direct-deploy safe), expand-only.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('dns_monitors', 'consecutive_failures')) {
            DB::statement('ALTER TABLE dns_monitors ADD COLUMN consecutive_failures smallint NOT NULL DEFAULT 0');
        }

        if (! Schema::hasColumn('dns_monitors', 'last_error')) {
            DB::statement('ALTER TABLE dns_monitors ADD COLUMN last_error text NULL');
        }

        if (! Schema::hasColumn('dns_monitors', 'last_error_at')) {
            DB::statement('ALTER TABLE dns_monitors ADD COLUMN last_error_at timestamp(0) without time zone NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dns_monitors DROP COLUMN IF EXISTS consecutive_failures');
        DB::statement('ALTER TABLE dns_monitors DROP COLUMN IF EXISTS last_error');
        DB::statement('ALTER TABLE dns_monitors DROP COLUMN IF EXISTS last_error_at');
    }
};
