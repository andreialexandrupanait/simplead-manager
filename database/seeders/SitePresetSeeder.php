<?php

namespace Database\Seeders;

use App\Models\SitePreset;
use Illuminate\Database\Seeder;

class SitePresetSeeder extends Seeder
{
    public function run(): void
    {
        SitePreset::updateOrCreate(
            ['name' => 'Full Monitoring'],
            [
                'description' => 'All modules enabled — uptime, backups, performance, security, analytics, search console, cloudflare, and database cleanup.',
                'modules' => [
                    'uptime' => ['enabled' => true, 'interval' => 5],
                    'backup' => ['enabled' => true],
                    'ssl' => ['enabled' => true],
                    'performance' => ['enabled' => true],
                    'security' => ['enabled' => true, 'interval' => 10080],
                    'analytics' => ['enabled' => true],
                    'search_console' => ['enabled' => true],
                    'cloudflare' => ['enabled' => true],
                    'database_cleanup' => ['enabled' => true],
                ],
                'is_default' => true,
                'sort_order' => 1,
            ]
        );

        SitePreset::updateOrCreate(
            ['name' => 'Standard Maintenance'],
            [
                'description' => 'Core monitoring and maintenance — uptime, backups, performance, and security. No analytics or external integrations.',
                'modules' => [
                    'uptime' => ['enabled' => true, 'interval' => 5],
                    'backup' => ['enabled' => true],
                    'ssl' => ['enabled' => true],
                    'performance' => ['enabled' => true],
                    'security' => ['enabled' => true, 'interval' => 10080],
                    'analytics' => ['enabled' => false],
                    'search_console' => ['enabled' => false],
                    'cloudflare' => ['enabled' => false],
                    'database_cleanup' => ['enabled' => false],
                ],
                'is_default' => false,
                'sort_order' => 2,
            ]
        );

        SitePreset::updateOrCreate(
            ['name' => 'Basic'],
            [
                'description' => 'Minimal setup — uptime monitoring and SSL checks only.',
                'modules' => [
                    'uptime' => ['enabled' => true, 'interval' => 5],
                    'backup' => ['enabled' => false],
                    'ssl' => ['enabled' => true],
                    'performance' => ['enabled' => false],
                    'security' => ['enabled' => false],
                    'analytics' => ['enabled' => false],
                    'search_console' => ['enabled' => false],
                    'cloudflare' => ['enabled' => false],
                    'database_cleanup' => ['enabled' => false],
                ],
                'is_default' => false,
                'sort_order' => 3,
            ]
        );
    }
}
