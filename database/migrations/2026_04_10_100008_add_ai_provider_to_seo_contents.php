<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE seo_contents ADD COLUMN IF NOT EXISTS ai_provider varchar(30) DEFAULT NULL');
        DB::statement('ALTER TABLE seo_contents ADD COLUMN IF NOT EXISTS ai_model varchar(80) DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE seo_contents DROP COLUMN IF EXISTS ai_provider');
        DB::statement('ALTER TABLE seo_contents DROP COLUMN IF EXISTS ai_model');
    }
};
