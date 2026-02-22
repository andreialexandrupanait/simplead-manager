<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_channels ALTER COLUMN config TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_channels ALTER COLUMN config TYPE json USING config::json');
    }
};
