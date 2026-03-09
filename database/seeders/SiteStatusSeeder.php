<?php

namespace Database\Seeders;

use App\Models\SiteStatus;
use Illuminate\Database\Seeder;

class SiteStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['name' => 'Active', 'color' => '#22c55e', 'sort_order' => 1],
            ['name' => 'Maintenance', 'color' => '#f59e0b', 'sort_order' => 2],
            ['name' => 'Degraded', 'color' => '#ef4444', 'sort_order' => 3],
            ['name' => 'Inactive', 'color' => '#6b7280', 'sort_order' => 4],
            ['name' => 'Paused', 'color' => '#8b5cf6', 'sort_order' => 5],
        ];

        foreach ($statuses as $status) {
            SiteStatus::firstOrCreate(['name' => $status['name']], $status);
        }
    }
}
