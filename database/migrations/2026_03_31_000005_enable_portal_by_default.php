<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: enable portal for all existing clients
        DB::unprepared('UPDATE clients SET portal_enabled = true WHERE portal_enabled = false');

        // Generate portal_token where missing (use PG md5+random for token generation)
        DB::unprepared('UPDATE clients SET portal_token = concat(md5(random()::text), md5(random()::text)) WHERE portal_token IS NULL');
    }

    public function down(): void
    {
        // No-op: portal can be toggled per-client
    }
};
