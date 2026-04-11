<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DnsMonitor;
use App\Models\Site;
use Illuminate\Console\Command;

class BackfillDnsMonitors extends Command
{
    protected $signature = 'dns:backfill-monitors';

    protected $description = 'Create DNS monitors for sites that don\'t have one';

    public function handle(): int
    {
        $sites = Site::where('is_connected', true)
            ->whereDoesntHave('dnsMonitor')
            ->get();

        $created = 0;
        foreach ($sites as $site) {
            $host = parse_url($site->url, PHP_URL_HOST);
            $domain = $host ? preg_replace('/^www\./', '', $host) : $site->url;
            DnsMonitor::create([
                'site_id' => $site->id,
                'domain' => $domain,
                'is_active' => true,
                'interval_minutes' => 360,
                'next_check_at' => now()->addMinutes(rand(1, 60)),
            ]);
            $created++;
        }

        $this->info("Created {$created} DNS monitors.");

        return 0;
    }
}
