<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PluginConflictSeeder::class);

        // Admin user
        User::factory()->create([
            'name' => 'Andrei',
            'email' => 'admin@simplead.ro',
            'password' => bcrypt('password'),
        ]);

        // Clients
        $simplead = Client::create([
            'name' => 'SimpleAd SRL',
            'email' => 'contact@simplead.ro',
            'phone' => '+40 721 000 001',
            'company' => 'SimpleAd SRL',
            'is_active' => true,
        ]);

        $flavor = Client::create([
            'name' => 'Flavor Studio',
            'email' => 'hello@flavorstudio.ro',
            'phone' => '+40 721 000 002',
            'company' => 'Flavor Studio SRL',
            'is_active' => true,
        ]);

        $digital = Client::create([
            'name' => 'Digital Craft Agency',
            'email' => 'info@digitalcraft.ro',
            'phone' => '+40 721 000 003',
            'company' => 'Digital Craft Agency SRL',
            'is_active' => true,
        ]);

        // Sites for SimpleAd SRL
        Site::create([
            'name' => 'SimpleAd Corporate',
            'url' => 'https://simplead.ro',
            'client_id' => $simplead->id,
            'status' => 'active',
            'health_score' => 98,
            'wp_version' => '6.5.0',
            'php_version' => '8.3',
            'server_software' => 'Nginx/1.24',
            'is_multisite' => false,
            'uptime_percentage' => 99.98,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(8),
            'pending_updates_count' => 0,
            'backup_ok' => true,
            'last_backup_at' => now()->subHours(6),
        ]);

        Site::create([
            'name' => 'SimpleAd Blog',
            'url' => 'https://blog.simplead.ro',
            'client_id' => $simplead->id,
            'status' => 'active',
            'health_score' => 85,
            'wp_version' => '6.4.2',
            'php_version' => '8.2',
            'server_software' => 'Nginx/1.24',
            'is_multisite' => false,
            'uptime_percentage' => 99.85,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(5),
            'pending_updates_count' => 3,
            'backup_ok' => true,
            'last_backup_at' => now()->subDay(),
        ]);

        Site::create([
            'name' => 'Ad Manager Portal',
            'url' => 'https://portal.simplead.ro',
            'client_id' => $simplead->id,
            'status' => 'active',
            'health_score' => 72,
            'wp_version' => '6.3.2',
            'php_version' => '8.1',
            'server_software' => 'Apache/2.4',
            'is_multisite' => true,
            'uptime_percentage' => 98.50,
            'is_up' => true,
            'ssl_ok' => false,
            'ssl_expiry' => now()->subDays(5),
            'pending_updates_count' => 8,
            'backup_ok' => false,
            'last_backup_at' => now()->subWeeks(2),
        ]);

        // Sites for Flavor Studio
        Site::create([
            'name' => 'Flavor Studio Website',
            'url' => 'https://flavorstudio.ro',
            'client_id' => $flavor->id,
            'status' => 'active',
            'health_score' => 95,
            'wp_version' => '6.5.0',
            'php_version' => '8.3',
            'server_software' => 'LiteSpeed',
            'is_multisite' => false,
            'uptime_percentage' => 99.95,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(10),
            'pending_updates_count' => 1,
            'backup_ok' => true,
            'last_backup_at' => now()->subHours(12),
        ]);

        Site::create([
            'name' => 'Flavor Recipes Blog',
            'url' => 'https://recipes.flavorstudio.ro',
            'client_id' => $flavor->id,
            'status' => 'active',
            'health_score' => 88,
            'wp_version' => '6.4.1',
            'php_version' => '8.2',
            'server_software' => 'LiteSpeed',
            'is_multisite' => false,
            'uptime_percentage' => 99.70,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(3),
            'pending_updates_count' => 4,
            'backup_ok' => true,
            'last_backup_at' => now()->subHours(18),
        ]);

        Site::create([
            'name' => 'Flavor E-Shop',
            'url' => 'https://shop.flavorstudio.ro',
            'client_id' => $flavor->id,
            'status' => 'active',
            'health_score' => 65,
            'wp_version' => '6.3.2',
            'php_version' => '8.1',
            'server_software' => 'Apache/2.4',
            'is_multisite' => false,
            'uptime_percentage' => 97.20,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(1),
            'pending_updates_count' => 11,
            'backup_ok' => false,
            'last_backup_at' => now()->subWeeks(3),
        ]);

        // Sites for Digital Craft Agency
        Site::create([
            'name' => 'Digital Craft Website',
            'url' => 'https://digitalcraft.ro',
            'client_id' => $digital->id,
            'status' => 'active',
            'health_score' => 92,
            'wp_version' => '6.5.0',
            'php_version' => '8.3',
            'server_software' => 'Nginx/1.24',
            'is_multisite' => false,
            'uptime_percentage' => 99.90,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(7),
            'pending_updates_count' => 2,
            'backup_ok' => true,
            'last_backup_at' => now()->subHours(3),
        ]);

        Site::create([
            'name' => 'Craft Portfolio',
            'url' => 'https://portfolio.digitalcraft.ro',
            'client_id' => $digital->id,
            'status' => 'active',
            'health_score' => 78,
            'wp_version' => '6.4.2',
            'php_version' => '8.2',
            'server_software' => 'Nginx/1.24',
            'is_multisite' => false,
            'uptime_percentage' => 99.40,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(2),
            'pending_updates_count' => 5,
            'backup_ok' => true,
            'last_backup_at' => now()->subDays(2),
        ]);

        Site::create([
            'name' => 'Client Hub Platform',
            'url' => 'https://hub.digitalcraft.ro',
            'client_id' => $digital->id,
            'status' => 'degraded',
            'health_score' => 68,
            'wp_version' => '6.4.1',
            'php_version' => '8.1',
            'server_software' => 'Apache/2.4',
            'is_multisite' => true,
            'uptime_percentage' => 96.80,
            'is_up' => false,
            'ssl_ok' => false,
            'ssl_expiry' => now()->subDays(10),
            'pending_updates_count' => 9,
            'backup_ok' => false,
            'last_backup_at' => now()->subMonth(),
        ]);

        Site::create([
            'name' => 'DC Learning Academy',
            'url' => 'https://learn.digitalcraft.ro',
            'client_id' => $digital->id,
            'status' => 'active',
            'health_score' => 91,
            'wp_version' => '6.5.0',
            'php_version' => '8.3',
            'server_software' => 'LiteSpeed',
            'is_multisite' => false,
            'uptime_percentage' => 99.92,
            'is_up' => true,
            'ssl_ok' => true,
            'ssl_expiry' => now()->addMonths(11),
            'pending_updates_count' => 0,
            'backup_ok' => true,
            'last_backup_at' => now()->subHours(1),
        ]);
    }
}
