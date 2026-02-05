<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Console\Command;

class BulkAddSites extends Command
{
    protected $signature = 'app:bulk-add-sites';
    protected $description = 'Bulk-add sites with their clients from a predefined list';

    public function handle(): int
    {
        $sites = [
            'M1 Med Beauty' => [
                'm1-beauty.co.uk',
                'm1-beauty.com.au',
                'm1-beauty.at',
                'm1-beauty.nl',
                'm1-beauty.hr',
                'm1-beauty.hu',
                'm1-beauty.ch',
                'm1-beauty.bg',
                'm1-beauty.ro',
                'm1-shop.de',
                'm1-select.de',
            ],
            'Matematica Interactiva' => ['matematica-interactiva.ro'],
            'FasTracKids' => ['fastrackids.ro'],
            'Florin Pasat' => ['florinpasat.com'],
            'Rollshape' => ['rollshape.ro'],
            'Priority Clinic' => ['priority-clinic.ro'],
            'Georgiana Ungureanu' => ['georgianaungureanu.ro'],
            'Hotel Simona Halep' => ['hotelsimonahalep.ro'],
            'Manuela Sirbu' => ['manuelasirbu.ro'],
            'GTA Energy' => ['gtaenergy.ro'],
            'Paul Ardeleanu' => ['paulardleanu.ro'],
            'Pallady ICHB' => ['pallady.ichb.ro'],
            'Universul Sacru' => ['universulsacru.ro'],
            'ISOR' => ['isor.ro'],
            'Premium Stone' => ['premiumstone.ro'],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($sites as $clientName => $domains) {
            $client = Client::firstOrCreate(['name' => $clientName]);
            $this->line("<fg=cyan>{$clientName}</> (client #{$client->id})");

            foreach ($domains as $domain) {
                $url = 'https://' . $domain;

                if (Site::where('url', $url)->exists()) {
                    $this->line("  <comment>SKIP</comment>  {$domain} (already exists)");
                    $skipped++;
                    continue;
                }

                Site::create([
                    'name' => $domain,
                    'url' => $url,
                    'client_id' => $client->id,
                    'type' => 'wordpress',
                    'status' => 'pending',
                ]);

                $this->line("  <info>ADDED</info> {$domain}");
                $created++;
            }
        }

        $this->newLine();
        $this->info("Done. Created: {$created}, Skipped: {$skipped}");

        return Command::SUCCESS;
    }
}
