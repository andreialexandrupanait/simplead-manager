<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupConfig;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Collection;

class MaintenancePlanService
{
    public function __construct(
        private ModuleConfigService $moduleConfigService,
        private SecuritySettingsService $securityService,
        private SiteTweaksSettingsService $tweaksService,
    ) {}

    /**
     * Create a plan by snapshotting a site's current configuration.
     */
    public function createFromSite(
        Site $site,
        string $name,
        string $description,
        array $sections,
    ): MaintenancePlan {
        $plan = MaintenancePlan::create([
            'name' => $name,
            'description' => $description,
            'is_default' => false,
            'sort_order' => 0,
            'include_modules' => in_array('modules', $sections),
            'include_security' => in_array('security', $sections),
            'include_tweaks' => in_array('tweaks', $sections),
            'source_site_id' => $site->id,
            'created_by' => auth()->id(),
            'security_settings' => in_array('security', $sections) ? $this->snapshotSecuritySettings($site) : null,
            'tweak_settings' => in_array('tweaks', $sections) ? $this->snapshotTweakSettings($site) : null,
        ]);

        if (in_array('modules', $sections)) {
            $this->snapshotModules($site, $plan);
        }

        return $plan;
    }

    /**
     * Apply a plan to multiple sites.
     */
    public function applyToSites(MaintenancePlan $plan, Collection $sites, array $sections): array
    {
        $pushed = 0;
        $total = $sites->count();

        foreach ($sites as $site) {
            $needsSecurityPush = false;
            $needsTweaksPush = false;

            if (in_array('modules', $sections) && $plan->include_modules) {
                $this->moduleConfigService->applyPlan($site, $plan);
                $this->applyBackupConfigFromPlan($plan, $site);
            }

            if (in_array('security', $sections) && $plan->include_security && ! empty($plan->security_settings)) {
                $this->applySecuritySettings($plan->security_settings, $site);
                $needsSecurityPush = true;
            }

            if (in_array('tweaks', $sections) && $plan->include_tweaks && ! empty($plan->tweak_settings)) {
                $this->applyTweakSettings($plan->tweak_settings, $site);
                $needsTweaksPush = true;
            }

            if ($site->is_connected) {
                if ($needsSecurityPush) {
                    $this->securityService->pushToPlugin($site);
                }
                if ($needsTweaksPush) {
                    $this->tweaksService->pushToPlugin($site);
                }
                $pushed++;
            }
        }

        return [
            'total' => $total,
            'pushed' => $pushed,
            'disconnected' => $total - $pushed,
        ];
    }

    /**
     * Snapshot security settings from a site into the plan JSON format.
     */
    private function snapshotSecuritySettings(Site $site): array
    {
        $categories = array_keys(SecuritySettingsService::VALID_SETTING_KEYS);

        return $site->securitySettings()
            ->whereIn('category', $categories)
            ->get()
            ->groupBy(fn ($s) => $s->category->value ?? $s->category)
            ->map(fn ($settings) => $settings->mapWithKeys(fn ($s) => [
                $s->setting_key => [
                    'value' => $s->setting_value,
                    'enabled' => $s->is_enabled,
                ],
            ]))
            ->toArray();
    }

    /**
     * Snapshot tweak settings from a site into the plan JSON format.
     */
    private function snapshotTweakSettings(Site $site): array
    {
        return $site->securitySettings()
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->get()
            ->groupBy(fn ($s) => $s->category->value ?? $s->category)
            ->map(fn ($settings) => $settings->mapWithKeys(fn ($s) => [
                $s->setting_key => [
                    'value' => $s->setting_value,
                    'enabled' => $s->is_enabled,
                ],
            ]))
            ->toArray();
    }

    /**
     * Snapshot module config from a site and create MaintenancePlanModule rows.
     */
    private function snapshotModules(Site $site, MaintenancePlan $plan): void
    {
        $config = $this->moduleConfigService->getConfig($site);

        foreach ($config as $moduleKey => $moduleConfig) {
            $moduleData = [
                'maintenance_plan_id' => $plan->id,
                'module_key' => $moduleKey,
                'is_enabled' => $moduleConfig['enabled'],
                'interval_minutes' => $moduleConfig['interval'],
            ];

            if ($moduleKey === 'backup') {
                $moduleData['config'] = $this->snapshotBackupConfig($site);
            }

            MaintenancePlanModule::create($moduleData);
        }
    }

    private function snapshotBackupConfig(Site $site): ?array
    {
        $backupConfig = $site->backupConfig;
        if (! $backupConfig) {
            return null;
        }

        return [
            'frequency' => $backupConfig->frequency,
            'time' => $backupConfig->time,
            'day_of_week' => $backupConfig->day_of_week,
            'day_of_month' => $backupConfig->day_of_month,
            'timezone' => $backupConfig->timezone,
            'type' => $backupConfig->type,
            'retention_type' => $backupConfig->retention_type,
            'retention_value' => $backupConfig->retention_value,
            'backup_before_updates' => $backupConfig->backup_before_updates,
        ];
    }

    /**
     * Apply security settings JSON to a site.
     */
    private function applySecuritySettings(array $settings, Site $site): void
    {
        foreach ($settings as $category => $categorySettings) {
            foreach ($categorySettings as $key => $config) {
                SecuritySetting::updateOrCreate(
                    ['site_id' => $site->id, 'category' => $category, 'setting_key' => $key],
                    [
                        'setting_value' => $config['value'] ?? null,
                        'is_enabled' => $config['enabled'] ?? false,
                        'failed_at' => null,
                        'failure_reason' => null,
                    ],
                );
            }
        }
    }

    /**
     * Apply tweak settings JSON to a site.
     */
    private function applyTweakSettings(array $settings, Site $site): void
    {
        foreach ($settings as $category => $categorySettings) {
            foreach ($categorySettings as $key => $config) {
                $this->tweaksService->applySetting(
                    $site,
                    $category,
                    $key,
                    $config['value'] ?? null,
                    $config['enabled'] ?? false,
                );
            }
        }
    }

    /**
     * Save (create or update) a maintenance plan with its modules.
     */
    public function savePlan(
        array $planData,
        array $moduleConfigs,
        ?array $securitySettings,
        ?array $tweakSettings,
        ?int $editingId = null,
    ): MaintenancePlan {
        if ($planData['is_default'] ?? false) {
            MaintenancePlan::where('is_default', true)
                ->when($editingId, fn ($q) => $q->where('id', '!=', $editingId))
                ->update(['is_default' => false]);
        }

        $data = array_merge($planData, [
            'security_settings' => $securitySettings,
            'tweak_settings' => $tweakSettings,
        ]);

        if (! $editingId) {
            $data['created_by'] = auth()->id();
        }

        $plan = MaintenancePlan::updateOrCreate(
            ['id' => $editingId],
            $data,
        );

        if ($planData['include_modules'] ?? false) {
            foreach ($moduleConfigs as $key => $moduleData) {
                MaintenancePlanModule::updateOrCreate(
                    ['maintenance_plan_id' => $plan->id, 'module_key' => $key],
                    $moduleData,
                );
            }
        }

        return $plan;
    }

    /**
     * Delete a maintenance plan if no sites are using it.
     *
     * @return array{success: bool, message: string}
     */
    public function deletePlan(int $planId): array
    {
        $plan = MaintenancePlan::withCount('sites')->findOrFail($planId);

        if ($plan->sites_count > 0) {
            return [
                'success' => false,
                'message' => "Cannot delete \"{$plan->name}\" — {$plan->sites_count} site(s) are using it.",
            ];
        }

        $plan->delete();

        return ['success' => true, 'message' => 'Plan deleted.'];
    }

    /**
     * Count total settings in a settings JSON blob.
     */
    public static function countSettings(?array $settings): int
    {
        if (! $settings) {
            return 0;
        }

        $count = 0;
        foreach ($settings as $categorySettings) {
            if (is_array($categorySettings)) {
                $count += count($categorySettings);
            }
        }

        return $count;
    }

    /**
     * Count enabled settings in a settings JSON blob.
     */
    public static function countEnabledSettings(?array $settings): int
    {
        if (! $settings) {
            return 0;
        }

        $count = 0;
        foreach ($settings as $categorySettings) {
            if (is_array($categorySettings)) {
                foreach ($categorySettings as $config) {
                    if ($config['enabled'] ?? false) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function applyBackupConfigFromPlan(MaintenancePlan $plan, Site $site): void
    {
        $backupModule = $plan->planModules->firstWhere('module_key', 'backup');
        if (! $backupModule || ! $backupModule->is_enabled || empty($backupModule->config)) {
            return;
        }

        $config = $backupModule->config;

        BackupConfig::updateOrCreate(
            ['site_id' => $site->id],
            [
                'frequency' => $config['frequency'] ?? 'daily',
                'time' => $config['time'] ?? '03:00',
                'day_of_week' => $config['day_of_week'] ?? 0,
                'day_of_month' => $config['day_of_month'] ?? 1,
                'timezone' => $config['timezone'] ?? 'Europe/Bucharest',
                'type' => $config['type'] ?? 'full',
                'retention_type' => $config['retention_type'] ?? 'count',
                'retention_value' => $config['retention_value'] ?? 10,
                'backup_before_updates' => $config['backup_before_updates'] ?? false,
            ],
        );
    }
}
