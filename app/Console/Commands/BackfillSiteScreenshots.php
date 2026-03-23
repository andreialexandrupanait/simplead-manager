<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunPerformanceTest;
use App\Models\Site;
use Illuminate\Console\Command;

class BackfillSiteScreenshots extends Command
{
    protected $signature = 'sites:backfill-screenshots';

    protected $description = 'Fetch screenshots for all sites without one';

    public function handle(): int
    {
        $sites = Site::whereNull('screenshot_path')->get();
        $queued = 0;

        foreach ($sites as $site) {
            // Create performance monitor if missing
            if (! $site->performanceMonitor) {
                $monitor = $site->performanceMonitor()->create([
                    'is_active' => true,
                    'frequency' => 'daily',
                    'test_time' => '04:00',
                ]);
            } else {
                $monitor = $site->performanceMonitor;
            }

            // Dispatch with delay to respect rate limits
            RunPerformanceTest::dispatch($monitor, 'desktop')->delay(now()->addSeconds($queued * 5));
            $queued++;
            $this->info("Queued screenshot fetch for: {$site->name}");
        }

        $this->info("Done. Queued {$queued} performance tests.");

        return Command::SUCCESS;
    }
}
