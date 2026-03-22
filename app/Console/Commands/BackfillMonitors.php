<?php

namespace App\Console\Commands;

use App\Jobs\CheckSslCertificate;
use App\Jobs\RunPerformanceTest;
use App\Models\Site;
use App\Models\SslCertificate;
use Illuminate\Console\Command;

class BackfillMonitors extends Command
{
    protected $signature = 'app:backfill-monitors {--retry-failed : Re-dispatch checks for monitors with error/pending status or missing expiry}';

    protected $description = 'Create SSL and performance monitors for existing sites that don\'t have them';

    public function handle(): int
    {
        $this->backfillMissing();

        if ($this->option('retry-failed')) {
            $this->retryFailed();
        }

        return Command::SUCCESS;
    }

    private function backfillMissing(): void
    {
        $sites = Site::all();
        $sslCreated = 0;
        $perfCreated = 0;

        foreach ($sites as $site) {
            // Create SSL certificate monitor if missing and site uses HTTPS
            if (!$site->sslCertificate && str_starts_with($site->url, 'https://')) {
                $certificate = $site->sslCertificate()->create([
                    'domain' => parse_url($site->url, PHP_URL_HOST),
                ]);
                CheckSslCertificate::dispatch($certificate);
                $sslCreated++;
                $this->info("Created SSL monitor for: {$site->name}");
            }

            // Create performance monitor if missing
            if (!$site->performanceMonitor) {
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

        $this->info("Backfill: created {$sslCreated} SSL, {$perfCreated} performance monitors.");
    }

    private function retryFailed(): void
    {
        $sslRetried = 0;

        // Re-dispatch SSL checks for monitors stuck in error/pending or missing expiry
        SslCertificate::where(function ($q) {
            $q->whereIn('status', ['error', 'pending'])
              ->orWhereNull('expires_at');
        })->each(function (SslCertificate $cert) use (&$sslRetried) {
            $cert->update(['status' => 'pending', 'error_message' => null]);
            CheckSslCertificate::dispatch($cert);
            $sslRetried++;
            $this->info("Retrying SSL check for: {$cert->domain}");
        });

        $this->info("Retry: re-dispatched {$sslRetried} SSL checks.");
    }
}
