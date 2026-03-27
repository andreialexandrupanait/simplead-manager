<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_status_check');
        DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('pending', 'in_progress', 'completed', 'failed', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_status_check');
        DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('pending', 'in_progress', 'completed', 'failed'))");
    }
};
