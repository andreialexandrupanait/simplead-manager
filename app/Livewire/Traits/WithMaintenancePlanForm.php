<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use App\Services\ModuleConfigService;
use App\Services\SecuritySettingsService;
use App\Services\SiteTweaksSettingsService;

trait WithMaintenancePlanForm
{
    // Create/Edit form
    public ?int $editingId = null;

    public string $planName = '';

    public string $planDescription = '';

    public array $planModules = [];

    public bool $planIsDefault = false;

    public int $planSortOrder = 0;

    // Include flags
    public bool $includeModules = true;

    public bool $includeSecurity = false;

    public bool $includeTweaks = false;

    // Security toggles
    public array $securityToggles = [];

    // Tweak toggles
    public array $tweakToggles = [];

    // Heartbeat sub-config
    public string $heartbeatFrontend = 'disable';

    public string $heartbeatDashboard = 'default';

    public string $heartbeatEditor = 'default';

    public int $heartbeatInterval = 60;

    // Revisions sub-config
    public int $revisionsLimit = 5;

    // Image sub-config
    public int $imageMaxWidth = 2560;

    public int $imageMaxHeight = 2560;

    public int $jpegQuality = 82;

    // Brute force sub-config
    public int $bruteForceMaxAttempts = 5;

    public int $bruteForceWindow = 10;

    public int $bruteForceBlockDuration = 60;

    // Backup config
    public string $backupFrequency = 'daily';

    public string $backupTime = '03:00';

    public string $backupTimezone = 'Europe/Bucharest';

    public string $backupType = 'full';

    public string $backupRetentionType = 'count';

    public int $backupRetentionValue = 10;

    public bool $backupBeforeUpdates = false;

    // Create from Site
    public ?int $sourceSiteId = null;

    public string $snapshotName = '';

    public string $snapshotDescription = '';

    public bool $snapshotModules = true;

    public bool $snapshotSecurity = true;

    public bool $snapshotTweaks = true;

    public function openCreate(): void
    {
        $this->resetForm();
        $this->view = 'create';
    }

    public function openEdit(int $id): void
    {
        $plan = MaintenancePlan::with('planModules')->findOrFail($id);

        $this->editingId = $plan->id;
        $this->planName = $plan->name;
        $this->planDescription = $plan->description ?? '';
        $this->planIsDefault = $plan->is_default;
        $this->planSortOrder = $plan->sort_order;
        $this->includeModules = $plan->include_modules;
        $this->includeSecurity = $plan->include_security;
        $this->includeTweaks = $plan->include_tweaks;
        $this->view = 'edit';

        // Load modules
        $this->planModules = [];
        foreach (ModuleConfigService::getModuleKeys() as $key) {
            $this->planModules[$key] = ['enabled' => false];
        }
        foreach ($plan->planModules as $mod) {
            $this->planModules[$mod->module_key] = ['enabled' => $mod->is_enabled];
        }

        // Load backup config from module
        $backupModule = $plan->planModules->firstWhere('module_key', 'backup');
        if ($backupModule && ! empty($backupModule->config)) {
            $bc = $backupModule->config;
            $this->backupFrequency = $bc['frequency'] ?? 'daily';
            $this->backupTime = $bc['time'] ?? '03:00';
            $this->backupTimezone = $bc['timezone'] ?? 'Europe/Bucharest';
            $this->backupType = $bc['type'] ?? 'full';
            $this->backupRetentionType = $bc['retention_type'] ?? 'count';
            $this->backupRetentionValue = $bc['retention_value'] ?? 10;
            $this->backupBeforeUpdates = $bc['backup_before_updates'] ?? false;
        } else {
            $this->resetBackupConfig();
        }

        // Load security toggles
        $this->initSecurityToggles();
        if (! empty($plan->security_settings)) {
            foreach ($plan->security_settings as $category => $settings) {
                foreach ($settings as $key => $config) {
                    if (array_key_exists($key, $this->securityToggles)) {
                        $this->securityToggles[$key] = $config['enabled'] ?? false;
                    }

                    // Load brute force sub-config
                    $value = $config['value'] ?? null;
                    if ($key === 'brute_force_protection' && is_array($value)) {
                        $this->bruteForceMaxAttempts = $value['max_attempts'] ?? 5;
                        $this->bruteForceWindow = $value['window_minutes'] ?? 10;
                        $this->bruteForceBlockDuration = $value['block_duration_minutes'] ?? 60;
                    }
                }
            }
        }

        // Load tweak toggles
        $this->initTweakToggles();
        if (! empty($plan->tweak_settings)) {
            foreach ($plan->tweak_settings as $category => $settings) {
                foreach ($settings as $key => $config) {
                    if (array_key_exists($key, $this->tweakToggles)) {
                        $this->tweakToggles[$key] = $config['enabled'] ?? false;
                    }

                    // Load sub-config values
                    $value = $config['value'] ?? null;
                    if ($key === 'heartbeat_control' && is_array($value)) {
                        $this->heartbeatFrontend = $value['frontend'] ?? 'disable';
                        $this->heartbeatDashboard = $value['dashboard'] ?? 'default';
                        $this->heartbeatEditor = $value['editor'] ?? 'default';
                        $this->heartbeatInterval = $value['interval'] ?? 60;
                    } elseif ($key === 'revisions_control' && is_array($value)) {
                        $this->revisionsLimit = $value['limit'] ?? 5;
                    } elseif ($key === 'image_upload_control' && is_array($value)) {
                        $this->imageMaxWidth = $value['max_width'] ?? 2560;
                        $this->imageMaxHeight = $value['max_height'] ?? 2560;
                        $this->jpegQuality = $value['jpeg_quality'] ?? 82;
                    }
                }
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'planName' => 'required|string|max:255',
            'planDescription' => 'nullable|string|max:500',
            'planSortOrder' => 'required|integer|min:0',
        ]);

        $securitySettings = $this->includeSecurity ? $this->buildSecuritySettings() : null;
        $tweakSettings = $this->includeTweaks ? $this->buildTweakSettings() : null;

        $planData = [
            'name' => $this->planName,
            'description' => $this->planDescription,
            'is_default' => $this->planIsDefault,
            'sort_order' => $this->planSortOrder,
            'include_modules' => $this->includeModules,
            'include_security' => $this->includeSecurity,
            'include_tweaks' => $this->includeTweaks,
        ];

        $moduleConfigs = [];
        foreach ($this->planModules as $key => $config) {
            $moduleData = ['is_enabled' => $config['enabled'] ?? false];

            if ($key === 'backup' && ($config['enabled'] ?? false)) {
                $moduleData['config'] = [
                    'frequency' => $this->backupFrequency,
                    'time' => $this->backupTime,
                    'timezone' => $this->backupTimezone,
                    'type' => $this->backupType,
                    'retention_type' => $this->backupRetentionType,
                    'retention_value' => $this->backupRetentionValue,
                    'backup_before_updates' => $this->backupBeforeUpdates,
                ];
            }

            $moduleConfigs[$key] = $moduleData;
        }

        $wasEditing = $this->editingId;

        app(MaintenancePlanService::class)->savePlan(
            $planData,
            $moduleConfigs,
            $securitySettings,
            $tweakSettings,
            $this->editingId,
        );

        $this->backToList();
        $this->dispatch('notify', type: 'success', message: $wasEditing ? 'Plan updated.' : 'Plan created.');
    }

    public function toggleModuleInForm(string $module): void
    {
        $current = $this->planModules[$module]['enabled'] ?? false;
        $this->planModules[$module]['enabled'] = ! $current;
    }

    public function toggleSecuritySetting(string $key): void
    {
        if (array_key_exists($key, $this->securityToggles)) {
            $this->securityToggles[$key] = ! $this->securityToggles[$key];
        }
    }

    public function toggleTweakSetting(string $key): void
    {
        if (array_key_exists($key, $this->tweakToggles)) {
            $this->tweakToggles[$key] = ! $this->tweakToggles[$key];
        }
    }

    public function openCreateFromSite(): void
    {
        $this->sourceSiteId = null;
        $this->snapshotName = '';
        $this->snapshotDescription = '';
        $this->snapshotModules = true;
        $this->snapshotSecurity = true;
        $this->snapshotTweaks = true;
        $this->view = 'create_from_site';
    }

    public function createFromSite(): void
    {
        $this->validate([
            'sourceSiteId' => 'required|exists:sites,id',
            'snapshotName' => 'required|string|max:255',
            'snapshotDescription' => 'nullable|string|max:500',
        ]);

        $sections = [];
        if ($this->snapshotModules) {
            $sections[] = 'modules';
        }
        if ($this->snapshotSecurity) {
            $sections[] = 'security';
        }
        if ($this->snapshotTweaks) {
            $sections[] = 'tweaks';
        }

        if (empty($sections)) {
            $this->dispatch('notify', type: 'error', message: 'Please select at least one section to include.');

            return;
        }

        $site = Site::findOrFail($this->sourceSiteId);

        app(MaintenancePlanService::class)->createFromSite(
            $site,
            $this->snapshotName,
            $this->snapshotDescription,
            $sections,
        );

        $this->backToList();
        $this->dispatch('notify', type: 'success', message: "Plan '{$this->snapshotName}' created from {$site->name}.");
    }

    public function countSettings(?array $settings): int
    {
        return MaintenancePlanService::countSettings($settings);
    }

    public function countEnabledSettings(?array $settings): int
    {
        return MaintenancePlanService::countEnabledSettings($settings);
    }

    private function buildSecuritySettings(): array
    {
        $securitySettings = [];
        foreach ($this->securitySettingLabels as $category => $group) {
            foreach ($group['settings'] as $key => $label) {
                $value = null;

                if ($key === 'brute_force_protection') {
                    $value = [
                        'max_attempts' => $this->bruteForceMaxAttempts,
                        'window_minutes' => $this->bruteForceWindow,
                        'block_duration_minutes' => $this->bruteForceBlockDuration,
                    ];
                }

                $securitySettings[$category][$key] = [
                    'enabled' => $this->securityToggles[$key] ?? false,
                    'value' => $value,
                ];
            }
        }

        return $securitySettings;
    }

    private function buildTweakSettings(): array
    {
        $tweakSettings = [];
        foreach ($this->tweakSettingLabels as $category => $group) {
            foreach ($group['settings'] as $key => $label) {
                $value = null;

                if ($key === 'heartbeat_control') {
                    $value = [
                        'frontend' => $this->heartbeatFrontend,
                        'dashboard' => $this->heartbeatDashboard,
                        'editor' => $this->heartbeatEditor,
                        'interval' => $this->heartbeatInterval,
                    ];
                } elseif ($key === 'revisions_control') {
                    $value = ['limit' => $this->revisionsLimit];
                } elseif ($key === 'image_upload_control') {
                    $value = [
                        'max_width' => $this->imageMaxWidth,
                        'max_height' => $this->imageMaxHeight,
                        'jpeg_quality' => $this->jpegQuality,
                    ];
                }

                $tweakSettings[$category][$key] = [
                    'enabled' => $this->tweakToggles[$key] ?? false,
                    'value' => $value,
                ];
            }
        }

        return $tweakSettings;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->planName = '';
        $this->planDescription = '';
        $this->planIsDefault = false;
        $this->planSortOrder = 0;
        $this->includeModules = true;
        $this->includeSecurity = false;
        $this->includeTweaks = false;
        $this->resetValidation();
        $this->initModuleForm();
        $this->initSecurityToggles();
        $this->initTweakToggles();
        $this->resetBackupConfig();
        $this->resetSubConfigs();
    }

    private function initModuleForm(): void
    {
        $this->planModules = [];
        foreach (ModuleConfigService::getModuleKeys() as $key) {
            $this->planModules[$key] = ['enabled' => false];
        }
    }

    private function initSecurityToggles(): void
    {
        $this->securityToggles = [];
        foreach (['hardening', 'htaccess'] as $category) {
            foreach (SecuritySettingsService::VALID_SETTING_KEYS[$category] as $key) {
                $this->securityToggles[$key] = false;
            }
        }
        // Login: only bulk-safe settings (exclude site-specific custom_login_url, two_factor_auth)
        $this->securityToggles['brute_force_protection'] = false;
    }

    private function initTweakToggles(): void
    {
        $this->tweakToggles = [];
        foreach (['performance', 'site_control'] as $category) {
            foreach (SiteTweaksSettingsService::VALID_SETTING_KEYS[$category] as $key) {
                $this->tweakToggles[$key] = false;
            }
        }
    }

    private function resetBackupConfig(): void
    {
        $this->backupFrequency = 'daily';
        $this->backupTime = '03:00';
        $this->backupTimezone = 'Europe/Bucharest';
        $this->backupType = 'full';
        $this->backupRetentionType = 'count';
        $this->backupRetentionValue = 10;
        $this->backupBeforeUpdates = false;
    }

    private function resetSubConfigs(): void
    {
        $this->heartbeatFrontend = 'disable';
        $this->heartbeatDashboard = 'default';
        $this->heartbeatEditor = 'default';
        $this->heartbeatInterval = 60;
        $this->revisionsLimit = 5;
        $this->imageMaxWidth = 2560;
        $this->imageMaxHeight = 2560;
        $this->jpegQuality = 82;
        $this->bruteForceMaxAttempts = 5;
        $this->bruteForceWindow = 10;
        $this->bruteForceBlockDuration = 60;
    }
}
