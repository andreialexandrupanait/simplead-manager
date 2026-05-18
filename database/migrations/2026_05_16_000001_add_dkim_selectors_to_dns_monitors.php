<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dns_monitors', 'dkim_selectors')) {
            DB::statement("ALTER TABLE dns_monitors ADD COLUMN dkim_selectors jsonb NOT NULL DEFAULT '[]'::jsonb");
        }

        DB::table('dns_monitors')
            ->where('is_active', true)
            ->update(['next_check_at' => now()]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dns_monitors DROP COLUMN IF EXISTS dkim_selectors');
    }
};
