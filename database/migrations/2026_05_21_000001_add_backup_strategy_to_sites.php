<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sites', 'backup_strategy')) {
            DB::statement("ALTER TABLE sites ADD COLUMN backup_strategy varchar(8) NOT NULL DEFAULT 'pull'");
            DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_backup_strategy_check CHECK (backup_strategy IN ('pull', 'push'))");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_backup_strategy_check');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS backup_strategy');
    }
};
