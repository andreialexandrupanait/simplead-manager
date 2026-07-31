<?php

namespace Database\Seeders;

use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use Illuminate\Database\Seeder;

/**
 * SPEC §9 — three plans, ascending: Bază, Standard, Premium. Each defines the
 * four things a plan is allowed to define: uptime level, backup rhythm, active
 * checks, report cadence.
 */
class MaintenancePlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Premium',
                'description' => 'All modules enabled — uptime, backups, performance, security, analytics, search console, cloudflare, and database cleanup.',
                'is_default' => true,
                'sort_order' => 3,
                'backup_frequency' => 'daily',
                'report_frequency' => 'monthly',
                'include_modules' => true,
                'include_security' => false,
                'include_tweaks' => false,
                'modules' => [
                    'uptime' => ['is_enabled' => true, 'interval_minutes' => 5],
                    'backup' => ['is_enabled' => true],
                    'ssl' => ['is_enabled' => true],
                    'performance' => ['is_enabled' => true],
                    'security' => ['is_enabled' => true, 'interval_minutes' => 10080],
                    'analytics' => ['is_enabled' => true],
                    'search_console' => ['is_enabled' => true],
                    'cloudflare' => ['is_enabled' => true],
                    'database_cleanup' => ['is_enabled' => true],
                ],
            ],
            [
                'name' => 'Standard',
                'description' => 'Core monitoring and maintenance — uptime, backups, performance, and security. No analytics or external integrations.',
                'is_default' => false,
                'sort_order' => 2,
                'backup_frequency' => 'daily',
                'report_frequency' => 'monthly',
                'include_modules' => true,
                'include_security' => false,
                'include_tweaks' => false,
                'modules' => [
                    'uptime' => ['is_enabled' => true, 'interval_minutes' => 5],
                    'backup' => ['is_enabled' => true],
                    'ssl' => ['is_enabled' => true],
                    'performance' => ['is_enabled' => true],
                    'security' => ['is_enabled' => true, 'interval_minutes' => 10080],
                    'analytics' => ['is_enabled' => false],
                    'search_console' => ['is_enabled' => false],
                    'cloudflare' => ['is_enabled' => false],
                    'database_cleanup' => ['is_enabled' => false],
                ],
            ],
            [
                'name' => 'Bază',
                'description' => 'Minimal setup — uptime monitoring and SSL checks only.',
                'is_default' => false,
                'sort_order' => 1,
                'backup_frequency' => 'weekly',
                'report_frequency' => 'monthly',
                'include_modules' => true,
                'include_security' => false,
                'include_tweaks' => false,
                'modules' => [
                    'uptime' => ['is_enabled' => true, 'interval_minutes' => 5],
                    'backup' => ['is_enabled' => false],
                    'ssl' => ['is_enabled' => true],
                    'performance' => ['is_enabled' => false],
                    'security' => ['is_enabled' => false],
                    'analytics' => ['is_enabled' => false],
                    'search_console' => ['is_enabled' => false],
                    'cloudflare' => ['is_enabled' => false],
                    'database_cleanup' => ['is_enabled' => false],
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $modules = $planData['modules'];
            unset($planData['modules']);

            $plan = MaintenancePlan::updateOrCreate(
                ['name' => $planData['name']],
                $planData
            );

            foreach ($modules as $moduleKey => $config) {
                MaintenancePlanModule::updateOrCreate(
                    ['maintenance_plan_id' => $plan->id, 'module_key' => $moduleKey],
                    [
                        'is_enabled' => $config['is_enabled'],
                        'interval_minutes' => $config['interval_minutes'] ?? null,
                    ]
                );
            }
        }
    }
}
