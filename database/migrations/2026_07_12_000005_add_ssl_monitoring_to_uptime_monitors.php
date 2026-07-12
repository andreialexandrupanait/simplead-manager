<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2-08: SSL-expiry monitoring was dead plumbing — the model documented
     * `check_ssl` / `ssl_expiry_threshold` and `uptime_checks.ssl_expires_at`
     * existed, but the columns to actually drive it were never created and no
     * job populated them. Add the columns so a queued check can fetch the peer
     * certificate expiry, store it, and surface near-expiry.
     *
     * Single-statement DDL each (PgBouncer-direct-deploy safe), expand-only.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('uptime_monitors', 'check_ssl')) {
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN check_ssl boolean NOT NULL DEFAULT true');
        }

        if (! Schema::hasColumn('uptime_monitors', 'ssl_expiry_threshold')) {
            // Days before expiry at which the monitor should warn.
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN ssl_expiry_threshold integer NOT NULL DEFAULT 14');
        }

        if (! Schema::hasColumn('uptime_monitors', 'ssl_expires_at')) {
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN ssl_expires_at timestamp(0) without time zone NULL');
        }

        if (! Schema::hasColumn('uptime_monitors', 'ssl_issuer')) {
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN ssl_issuer character varying(255) NULL');
        }

        if (! Schema::hasColumn('uptime_monitors', 'ssl_last_checked_at')) {
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN ssl_last_checked_at timestamp(0) without time zone NULL');
        }

        if (! Schema::hasColumn('uptime_monitors', 'ssl_last_error')) {
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN ssl_last_error character varying(255) NULL');
        }

        if (! Schema::hasColumn('uptime_monitors', 'next_ssl_check_at')) {
            DB::statement('ALTER TABLE uptime_monitors ADD COLUMN next_ssl_check_at timestamp(0) without time zone NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS check_ssl');
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS ssl_expiry_threshold');
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS ssl_expires_at');
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS ssl_issuer');
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS ssl_last_checked_at');
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS ssl_last_error');
        DB::statement('ALTER TABLE uptime_monitors DROP COLUMN IF EXISTS next_ssl_check_at');
    }
};
