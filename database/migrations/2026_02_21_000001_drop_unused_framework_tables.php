<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sessions, cache, and cache_locks are handled by Redis (SESSION_DRIVER=redis, CACHE_DRIVER=redis)
        // job_batches is unused (Bus::batch never called)
        $tables = [
            'sessions',
            'cache',
            'cache_locks',
            'job_batches',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Re-run original framework migrations to restore
    }
};
