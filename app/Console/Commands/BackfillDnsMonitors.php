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
            DnsMonitor::create([
                'site_id' => $site->id,
                'domain' => $site->dnsDomain(),
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
