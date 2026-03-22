<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        // Seed site_health_state for all existing sites
        if ($isSqlite) {
            DB::statement("
                INSERT OR IGNORE INTO site_health_state (site_id, created_at, updated_at)
                SELECT id, datetime('now'), datetime('now') FROM sites WHERE deleted_at IS NULL
            ");
        } else {
            DB::statement('
                INSERT INTO site_health_state (site_id, created_at, updated_at)
                SELECT id, NOW(), NOW() FROM sites WHERE deleted_at IS NULL
                ON CONFLICT (site_id) DO NOTHING
            ');
        }

        if ($isSqlite) {
            // SQLite: use datetime with random offset in seconds
            DB::table('analytics_connections')
                ->whereNull('next_sync_at')
                ->where('is_active', true)
                ->update(['next_sync_at' => DB::raw("datetime('now', '+' || (abs(random()) % 7200) || ' seconds')")]);

            DB::table('search_console_connections')
                ->whereNull('next_sync_at')
                ->where('is_active', true)
                ->update(['next_sync_at' => DB::raw("datetime('now', '+' || (abs(random()) % 7200) || ' seconds')")]);

            DB::table('site_cloudflare')
                ->whereNull('next_sync_at')
                ->update(['next_sync_at' => DB::raw("datetime('now', '+' || (abs(random()) % 3600) || ' seconds')")]);

            DB::table('performance_monitors')
                ->whereNull('next_test_at')
                ->where('is_active', true)
                ->update(['next_test_at' => DB::raw("datetime('now', '+' || (abs(random()) % 3600) || ' seconds')")]);
        } else {
            // PostgreSQL: use INTERVAL with RANDOM()
            DB::table('analytics_connections')
                ->whereNull('next_sync_at')
                ->where('is_active', true)
                ->update(['next_sync_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '120 minutes')")]);

            DB::table('search_console_connections')
                ->whereNull('next_sync_at')
                ->where('is_active', true)
                ->update(['next_sync_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '120 minutes')")]);

            DB::table('site_cloudflare')
                ->whereNull('next_sync_at')
                ->update(['next_sync_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '60 minutes')")]);

            DB::table('performance_monitors')
                ->whereNull('next_test_at')
                ->where('is_active', true)
                ->update(['next_test_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '60 minutes')")]);
        }
    }

    public function down(): void
    {
        // No rollback — backfill data is harmless
    }
};
