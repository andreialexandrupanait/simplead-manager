<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PluginConflictSeeder::class);
        $this->call(SiteStatusSeeder::class);
        $this->call(SitePresetSeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
