<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Seed site_health_state for all existing sites
        DB::statement("
            INSERT INTO site_health_state (site_id, created_at, updated_at)
            SELECT id, NOW(), NOW() FROM sites WHERE deleted_at IS NULL
            ON CONFLICT (site_id) DO NOTHING
        ");

        // Backfill next_sync_at with jitter for analytics_connections
        DB::table('analytics_connections')
            ->whereNull('next_sync_at')
            ->where('is_active', true)
            ->update([
                'next_sync_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '120 minutes')"),
            ]);

        // Backfill next_sync_at with jitter for search_console_connections
        DB::table('search_console_connections')
            ->whereNull('next_sync_at')
            ->where('is_active', true)
            ->update([
                'next_sync_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '120 minutes')"),
            ]);

        // Backfill next_sync_at with jitter for site_cloudflare
        DB::table('site_cloudflare')
            ->whereNull('next_sync_at')
            ->update([
                'next_sync_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '60 minutes')"),
            ]);

        // Backfill performance_monitors next_test_at with jitter where null
        DB::table('performance_monitors')
            ->whereNull('next_test_at')
            ->where('is_active', true)
            ->update([
                'next_test_at' => DB::raw("NOW() + (RANDOM() * INTERVAL '60 minutes')"),
            ]);
    }

    public function down(): void
    {
        // No rollback — backfill data is harmless
    }
};
