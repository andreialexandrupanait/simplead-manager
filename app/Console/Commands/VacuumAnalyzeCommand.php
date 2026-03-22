<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VacuumAnalyzeCommand extends Command
{
    protected $signature = 'db:vacuum-analyze';

    protected $description = 'Run PostgreSQL VACUUM ANALYZE to reclaim storage and update statistics';

    public function handle(): void
    {
        DB::statement('VACUUM ANALYZE');
    }
}
