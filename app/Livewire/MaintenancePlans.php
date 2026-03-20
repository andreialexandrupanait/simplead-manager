<?php

namespace App\Livewire;

use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use App\Services\ModuleConfigService;
use App\Services\SecuritySettingsService;
use App\Services\SiteTweaksSettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MaintenancePlans extends Component
{
    // View mode: list, apply, create, edit, create_from_site
    public string $view = 'list';

    // Apply mode
    public ?int $applyingPlanId = null;
    public string $siteSearch = '';
    public array $selectedSiteIds = [];
    public bool $selectAll = false;
    public bool $applyModules = true;
    public bool $applySecurity = true;
    public bool $applyTweaks = true;

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

    // Delete confirmation
    public ?int $confirmDeleteId = null;

    public function mount(): void
    {
        $this->initModuleForm();
        $this->initSecurityToggles();
        $this->initTweakToggles();
    }

    #[Computed]
    public function plans()
    {
        return MaintenancePlan::with('modules')
            ->withCount('sites')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function sites()
    {
        $query = Site::query()
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name');

        if ($this->siteSearch) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->siteSearch}%")
                    ->orWhere('url', 'ilike', "%{$this->siteSearch}%");
            });
        }

        return $query->get();
    }

    #[Computed]
    public function sourceSites()
    {
        return Site::query()
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function moduleKeys(): array
    {
        return ModuleConfigService::getModuleKeys();
    }

    #[Computed]
    public function moduleLabels(): array
    {
        return [
            'uptime' => 'Uptime Monitoring',
            'backup' => 'Backups',
            'ssl' => 'SSL Monitoring',
            'performance' => 'Performance Tests',
            'security' => 'Security Scans',
            'analytics' => 'Google Analytics',
            'search_console' => 'Search Console',
            'cloudflare' => 'Cloudflare',
            'database_cleanup' => 'Database Cleanup',
        ];
    }

    #[Computed]
    public function securitySettingLabels(): array
    {
        return [
            'hardening' => [
                'title' => 'WordPress Hardening',
                'settings' => [
                    'disable_theme_editor' => 'Disable Theme/Plugin Editor',
                    'disable_user_enumeration' => 'Disable User Enumeration',
                    'hide_wp_version' => 'Hide WordPress Version',
                    'restrict_xmlrpc' => 'Restrict XML-RPC',
                    'security_headers' => 'Security Headers',
                    'block_application_passwords' => 'Block Application Passwords',
                    'restrict_rest_api' => 'Restrict REST API',
                ],
            ],
            'htaccess' => [
                'title' => '.htaccess Rules',
                'settings' => [
                    'block_default_files' => 'Block Default Files',
                    'block_readme_access' => 'Block Readme Access',
                    'block_debug_log' => 'Block Debug Log',
                    'disable_directory_listing' => 'Disable Directory Listing',
                    'firewall_enabled' => 'Basic Firewall',
                ],
            ],
            'login' => [
                'title' => 'Login Protection',
                'settings' => [
                    'brute_force_protection' => 'Brute Force Protection',
                ],
            ],
        ];
    }

    #[Computed]
    public function tweakSettingLabels(): array
    {
        return [
            'performance' => [
                'title' => 'Performance',
                'settings' => [
                    'heartbeat_control' => 'Heartbeat Control',
                    'revisions_control' => 'Limit Post Revisions',
                    'image_upload_control' => 'Image Optimization',
                    'disable_emojis' => 'Disable Emojis',
                    'disable_dashicons' => 'Disable Dashicons',
                    'disable_jquery_migrate' => 'Disable jQuery Migrate',
                    'disable_generator_tag' => 'Disable Generator Tag',
                    'disable_wlw_manifest' => 'Disable WLW Manifest',
                    'disable_rsd_link' => 'Disable RSD Link',
                    'disable_shortlinks' => 'Disable Shortlinks',
                    'disable_lazy_load' => 'Disable Native Lazy Load',
                    'disable_block_widgets' => 'Disable Block Widgets',
                ],
            ],
            'site_control' => [
                'title' => 'Site Control',
                'settings' => [
                    'disable_all_updates' => 'Disable All Auto-Updates',
                    'disable_comments' => 'Disable Comments',
                    'disable_feeds' => 'Disable RSS Feeds',
                    'disable_embeds' => 'Disable Embeds',
                    'redirect_404' => 'Redirect 404 to Homepage',
                    'disable_gutenberg' => 'Disable Gutenberg Editor',
                    'disable_author_archives' => 'Disable Author Archives',
                ],
            ],
        ];
    }

    // --- Apply ---

    public function startApply(int $planId): void
    {
        $plan = MaintenancePlan::with('modules')->findOrFail($planId);

        $this->applyingPlanId = $planId;
        $this->selectedSiteIds = [];
        $this->selectAll = false;
        $this->siteSearch = '';
        $this->applyModules = $plan->include_modules;
        $this->applySecurity = $plan->include_security;
        $this->applyTweaks = $plan->include_tweaks;
        $this->view = 'apply';
    }

    public function applyPlan(): void
    {
        if (empty($this->selectedSiteIds)) {
            $this->dispatch('notify', type: 'error', message: 'Please select at least one site.');
            return;
        }

        $plan = MaintenancePlan::with('modules')->find($this->applyingPlanId);
        if (!$plan) {
            $this->dispatch('notify', type: 'error', message: 'Plan not found.');
            return;
        }

        $sections = [];
        if ($this->applyModules) $sections[] = 'modules';
        if ($this->applySecurity) $sections[] = 'security';
        if ($this->applyTweaks) $sections[] = 'tweaks';

        if (empty($sections)) {
            $this->dispatch('notify', type: 'error', message: 'Please select at least one section to apply.');
            return;
        }

        $scopedQuery = Site::query()
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()));
        $targets = $scopedQuery->whereIn('id', $this->selectedSiteIds)->get();

        if ($targets->isEmpty()) {
            $this->dispatch('notify', type: 'error', message: 'No valid target sites found.');
            return;
        }

        $result = app(MaintenancePlanService::class)->applyToSites($plan, $targets, $sections);

        $this->backToList();

        $message = "Plan '{$plan->name}' applied to {$result['total']} site(s).";
        if ($result['pushed'] > 0) {
            $message .= " Pushing to {$result['pushed']} connected site(s).";
        }
        if ($result['disconnected'] > 0) {
            $message .= " {$result['disconnected']} disconnected site(s) will receive settings when connected.";
        }
        $this->dispatch('notify', type: 'success', message: $message);
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedSiteIds = $this->sites->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedSiteIds = [];
        }
    }

    public function updatedSiteSearch(): void
    {
        $this->siteSearch = substr(trim($this->siteSearch), 0, 100);
        unset($this->sites);
    }

    // --- Create/Edit ---

    public function openCreate(): void
    {
        $this->resetForm();
        $this->view = 'create';
    }

    public function openEdit(int $id): void
    {
        $plan = MaintenancePlan::with('modules')->findOrFail($id);

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
        foreach ($plan->modules as $mod) {
            $this->planModules[$mod->module_key] = ['enabled' => $mod->is_enabled];
        }

        // Load backup config from module
        $backupModule = $plan->modules->firstWhere('module_key', 'backup');
        if ($backupModule && !empty($backupModule->config)) {
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
        if (!empty($plan->security_settings)) {
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
        if (!empty($plan->tweak_settings)) {
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

        if ($this->planIsDefault) {
            MaintenancePlan::where('is_default', true)
                ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
                ->update(['is_default' => false]);
        }

        // Build security settings JSON
        $securitySettings = null;
        if ($this->includeSecurity) {
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
        }

        // Build tweak settings JSON
        $tweakSettings = null;
        if ($this->includeTweaks) {
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
        }

        $data = [
            'name' => $this->planName,
            'description' => $this->planDescription,
            'is_default' => $this->planIsDefault,
            'sort_order' => $this->planSortOrder,
            'include_modules' => $this->includeModules,
            'include_security' => $this->includeSecurity,
            'include_tweaks' => $this->includeTweaks,
            'security_settings' => $securitySettings,
            'tweak_settings' => $tweakSettings,
        ];

        if (!$this->editingId) {
            $data['created_by'] = auth()->id();
        }

        $plan = MaintenancePlan::updateOrCreate(
            ['id' => $this->editingId],
            $data,
        );

        // Save modules
        if ($this->includeModules) {
            foreach ($this->planModules as $key => $config) {
                $moduleData = ['is_enabled' => $config['enabled'] ?? false];

                // Save backup config on the backup module
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

                MaintenancePlanModule::updateOrCreate(
                    ['maintenance_plan_id' => $plan->id, 'module_key' => $key],
                    $moduleData,
                );
            }
        }

        $wasEditing = $this->editingId;
        $this->backToList();
        $this->dispatch('notify', type: 'success', message: $wasEditing ? 'Plan updated.' : 'Plan created.');
    }

    public function toggleModuleInForm(string $module): void
    {
        $current = $this->planModules[$module]['enabled'] ?? false;
        $this->planModules[$module]['enabled'] = !$current;
    }

    public function toggleSecuritySetting(string $key): void
    {
        if (array_key_exists($key, $this->securityToggles)) {
            $this->securityToggles[$key] = !$this->securityToggles[$key];
        }
    }

    public function toggleTweakSetting(string $key): void
    {
        if (array_key_exists($key, $this->tweakToggles)) {
            $this->tweakToggles[$key] = !$this->tweakToggles[$key];
        }
    }

    // --- Create from Site ---

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
        if ($this->snapshotModules) $sections[] = 'modules';
        if ($this->snapshotSecurity) $sections[] = 'security';
        if ($this->snapshotTweaks) $sections[] = 'tweaks';

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

    // --- Delete ---

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function delete(): void
    {
        if (!$this->confirmDeleteId) return;

        $plan = MaintenancePlan::withCount('sites')->findOrFail($this->confirmDeleteId);

        if ($plan->sites_count > 0) {
            $this->dispatch('notify', type: 'error', message: "Cannot delete \"{$plan->name}\" — {$plan->sites_count} site(s) are using it.");
            $this->confirmDeleteId = null;
            return;
        }

        $plan->delete();
        $this->confirmDeleteId = null;
        unset($this->plans);
        $this->dispatch('notify', type: 'success', message: 'Plan deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    // --- Navigation ---

    public function backToList(): void
    {
        $this->view = 'list';
        $this->resetForm();
        $this->applyingPlanId = null;
        $this->selectedSiteIds = [];
        $this->selectAll = false;
        $this->siteSearch = '';
        $this->confirmDeleteId = null;
        unset($this->plans, $this->sites);
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

    /**
     * Count settings in a JSON settings blob (for display).
     */
    public function countSettings(?array $settings): int
    {
        if (!$settings) return 0;

        $count = 0;
        foreach ($settings as $categorySettings) {
            if (is_array($categorySettings)) {
                $count += count($categorySettings);
            }
        }

        return $count;
    }

    /**
     * Count enabled settings in a JSON settings blob (for display).
     */
    public function countEnabledSettings(?array $settings): int
    {
        if (!$settings) return 0;

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

    public function render()
    {
        return view('livewire.maintenance-plans')
            ->layout('components.layouts.app', [
                'title' => 'Maintenance Plans',
            ]);
    }
}
