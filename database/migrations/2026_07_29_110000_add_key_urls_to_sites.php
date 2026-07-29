<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §7.5 — the derived "key URLs" for a site (homepage + top Search-Console
 * pages), used by the update smoke check and reports. Recomputed when GSC data
 * refreshes. Raw DDL + non-transactional (PgBouncer 25P02 convention).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS key_urls jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS key_urls');
    }
};
