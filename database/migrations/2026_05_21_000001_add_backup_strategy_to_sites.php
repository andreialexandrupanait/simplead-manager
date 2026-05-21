<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each ALTER runs in its own transaction to avoid the
     * "current transaction is aborted" cascade that bites when running through
     * pgbouncer with transaction pooling.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TABLE sites ADD COLUMN IF NOT EXISTS backup_strategy varchar(8) NOT NULL DEFAULT 'pull'");

        $exists = DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'sites_backup_strategy_check'");
        if (! $exists) {
            DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_backup_strategy_check CHECK (backup_strategy IN ('pull', 'push'))");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_backup_strategy_check');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS backup_strategy');
    }
};
