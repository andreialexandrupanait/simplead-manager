<?php

namespace Database\Seeders;

use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Client;
use App\Models\DomainMonitor;
use App\Models\NotificationChannel;
use App\Models\PerformanceTest;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\SslCertificate;
use App\Models\StorageDestination;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@simplead.test',
            'role' => UserRole::Admin,
        ]);

        // Manager user
        User::factory()->create([
            'name' => 'Manager User',
            'email' => 'manager@simplead.test',
            'role' => UserRole::Manager,
        ]);

        // Viewer user
        User::factory()->create([
            'name' => 'Viewer User',
            'email' => 'viewer@simplead.test',
            'role' => UserRole::Viewer,
        ]);

        // Clients
        $clients = Client::factory(5)->create();

        // Storage destination
        $storage = StorageDestination::factory()->create([
            'name' => 'Local Storage',
            'driver' => 'local',
        ]);

        // Notification channel
        NotificationChannel::factory()->create([
            'type' => 'email',
            'name' => 'Admin Email',
        ]);

        // Report template
        $template = ReportTemplate::factory()->create();

        // Sites with full setup
        $sites = Site::factory(10)->create([
            'user_id' => $admin->id,
        ])->each(function (Site $site) use ($clients, $storage, $template) {
            // Assign a random client
            $site->update(['client_id' => $clients->random()->id]);

            // Uptime monitor
            UptimeMonitor::factory()->create(['site_id' => $site->id]);

            // SSL certificate
            SslCertificate::factory()->create(['site_id' => $site->id]);

            // Domain monitor
            DomainMonitor::factory()->create(['site_id' => $site->id]);

            // Backup config
            BackupConfig::factory()->create([
                'site_id' => $site->id,
                'storage_destination_id' => $storage->id,
            ]);

            // Backups (3 completed, 1 failed)
            Backup::factory(3)->create([
                'site_id' => $site->id,
                'storage_destination_id' => $storage->id,
                'status' => BackupStatus::Completed,
            ]);
            Backup::factory()->create([
                'site_id' => $site->id,
                'storage_destination_id' => $storage->id,
                'status' => BackupStatus::Failed,
            ]);

            // Plugins (5-15 per site)
            SitePlugin::factory(fake()->numberBetween(5, 15))->create([
                'site_id' => $site->id,
            ]);

            // Themes (2-4 per site)
            SiteTheme::factory(fake()->numberBetween(2, 4))->create([
                'site_id' => $site->id,
            ]);

            // Performance tests
            PerformanceTest::factory(3)->create(['site_id' => $site->id]);

            // Security scan
            SecurityScan::factory()->create(['site_id' => $site->id]);

            // Report schedule
            ReportSchedule::factory()->create([
                'site_id' => $site->id,
                'report_template_id' => $template->id,
            ]);

            // Reports
            Report::factory(2)->create([
                'site_id' => $site->id,
                'report_template_id' => $template->id,
            ]);

            // Activity logs
            ActivityLog::factory(5)->create(['site_id' => $site->id]);
        });

        // A few critical sites
        $sites->take(2)->each(function (Site $site) {
            $site->update([
                'is_up' => false,
                'health_score' => fake()->numberBetween(10, 40),
            ]);
        });

        // A few warning sites
        $sites->skip(2)->take(3)->each(function (Site $site) {
            $site->update([
                'health_score' => fake()->numberBetween(50, 74),
            ]);
        });

        $this->command->info('Development data seeded: 3 users, 5 clients, 10 sites with full monitoring data.');
    }
}
