<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PluginConflictSeeder::class);
        $this->call(SiteStatusSeeder::class);
        $this->call(MaintenancePlanSeeder::class);
        $this->call(SecurityPresetSeeder::class);
        $this->call(AuditChecksSeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
