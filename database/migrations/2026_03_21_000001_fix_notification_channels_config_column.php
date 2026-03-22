<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix: the previous migration (2026_02_22_100001) was marked as ran
        // but the column is still json. Force-change it to text.
        // The encrypted:array cast on the model stores encrypted base64 strings,
        // not valid JSON, so the column must be text.
        DB::statement('ALTER TABLE notification_channels ALTER COLUMN config TYPE text USING config::text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_channels ALTER COLUMN config TYPE json USING config::json');
    }
};
