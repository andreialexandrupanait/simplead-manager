<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS ai_context text DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS ai_context');
    }
};
