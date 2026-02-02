<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use App\Models\Site;
use Illuminate\Console\Command;

class BackfillMonitors extends Command
{
    protected $signature = 'app:backfill-monitors';

    protected $description = 'Create SSL and domain monitors for existing sites that don\'t have them';

    public function handle(): int
    {
        $sites = Site::all();
        $sslCreated = 0;
        $domainCreated = 0;

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

            // Create domain monitor if missing
            if (!$site->domainMonitor) {
                $rootDomain = Site::extractRootDomain($site->url);
                $parts = explode('.', $rootDomain);
                $tld = end($parts);

                $domainMonitor = $site->domainMonitor()->create([
                    'domain' => $rootDomain,
                    'tld' => $tld,
                ]);
                CheckDomainExpiry::dispatch($domainMonitor);
                $domainCreated++;
                $this->info("Created domain monitor for: {$site->name}");
            }
        }

        $this->info("Done. Created {$sslCreated} SSL monitors and {$domainCreated} domain monitors.");

        return Command::SUCCESS;
    }
}
