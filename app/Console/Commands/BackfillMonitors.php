<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use Illuminate\Console\Command;

class BackfillMonitors extends Command
{
    protected $signature = 'app:backfill-monitors';

    protected $description = 'Create performance monitors for existing sites that don\'t have them';

    public function handle(): int
    {
        $this->backfillMissing();

        return Command::SUCCESS;
    }

    private function backfillMissing(): void
    {
        $sites = Site::all();
        $perfCreated = 0;

        foreach ($sites as $site) {
            // Create performance monitor if missing
            if (! $site->performanceMonitor) {
                /** @var PerformanceMonitor $monitor */
                $monitor = $site->performanceMonitor()->create([
                    'is_active' => true,
                    'frequency' => 'daily',
                    'test_time' => '04:00',
                ]);
                RunPerformanceTest::dispatch($monitor, 'both');
                $perfCreated++;
                $this->info("Created performance monitor for: {$site->name}");
            }
        }

        $this->info("Backfill: created {$perfCreated} performance monitors.");
    }
}
