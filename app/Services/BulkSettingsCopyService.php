<?php

namespace App\Services;

use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Collection;

class BulkSettingsCopyService
{
    public function __construct(
        private SecuritySettingsService $securityService,
        private SiteTweaksSettingsService $tweaksService,
        private ModuleConfigService $moduleConfigService,
    ) {}

    /**
     * Copy security settings from source site to target sites.
     * Settings are saved to DB for all targets. Push jobs only dispatched for connected sites.
     */
    public function copySecuritySettings(Site $source, Collection $targets): array
    {
        $categories = array_keys(SecuritySettingsService::VALID_SETTING_KEYS);
        $settings = $source->securitySettings()
            ->whereIn('category', $categories)
            ->get();

        if ($settings->isEmpty()) {
            return ['total' => 0, 'pushed' => 0];
        }

        $pushed = 0;
        foreach ($targets as $target) {
            foreach ($settings as $setting) {
                $category = $setting->category->value ?? $setting->category;
                SecuritySetting::updateOrCreate(
                    ['site_id' => $target->id, 'category' => $category, 'setting_key' => $setting->setting_key],
                    ['setting_value' => $setting->setting_value, 'is_enabled' => $setting->is_enabled, 'failed_at' => null, 'failure_reason' => null],
                );
            }

            if ($target->is_connected) {
                $this->securityService->pushToPlugin($target);
                $pushed++;
            }
        }

        return ['total' => $targets->count(), 'pushed' => $pushed];
    }

    /**
     * Copy tweak settings from source site to target sites.
     * Settings are saved to DB for all targets. Push jobs only dispatched for connected sites.
     */
    public function copyTweakSettings(Site $source, Collection $targets): array
    {
        $categories = SiteTweaksSettingsService::TWEAK_CATEGORIES;
        $settings = $source->securitySettings()
            ->whereIn('category', $categories)
            ->get()
            ->groupBy(fn ($s) => $s->category->value ?? $s->category);

        if ($settings->isEmpty()) {
            return ['total' => 0, 'pushed' => 0];
        }

        $pushed = 0;
        foreach ($targets as $target) {
            foreach ($settings as $category => $categorySettings) {
                foreach ($categorySettings as $s) {
                    $this->tweaksService->applySetting($target, $category, $s->setting_key, $s->setting_value, $s->is_enabled);
                }
            }

            if ($target->is_connected) {
                $this->tweaksService->pushToPlugin($target);
                $pushed++;
            }
        }

        return ['total' => $targets->count(), 'pushed' => $pushed];
    }

    /**
     * Apply a security preset to target sites.
     * Settings are saved to DB for all targets. Push jobs only dispatched for connected sites.
     */
    public function applySecurityPreset(SecurityPreset $preset, Collection $targets): array
    {
        $settings = $preset->settings;

        if (empty($settings)) {
            return ['total' => 0, 'pushed' => 0];
        }

        $pushed = 0;
        foreach ($targets as $site) {
            foreach ($settings as $category => $categorySettings) {
                foreach ($categorySettings as $key => $config) {
                    SecuritySetting::updateOrCreate(
                        ['site_id' => $site->id, 'category' => $category, 'setting_key' => $key],
                        ['setting_value' => $config['value'] ?? null, 'is_enabled' => $config['enabled'] ?? false, 'failed_at' => null, 'failure_reason' => null],
                    );
                }
            }

            $site->securityPresets()->syncWithoutDetaching([
                $preset->id => [
                    'applied_at' => now(),
                    'applied_version' => $preset->version,
                ],
            ]);

            if ($site->is_connected) {
                $this->securityService->pushToPlugin($site);
                $pushed++;
            }
        }

        return ['total' => $targets->count(), 'pushed' => $pushed];
    }

    /**
     * Copy module configuration from source site to target sites.
     */
    public function copyModuleConfig(Site $source, Collection $targets): int
    {
        $sourceConfig = $this->moduleConfigService->getConfig($source);

        $count = 0;
        foreach ($targets as $target) {
            foreach ($sourceConfig as $moduleKey => $config) {
                // Skip connection-required modules that aren't connected on source
                if ($config['requires_connection'] && !$config['is_connected']) {
                    continue;
                }

                $this->moduleConfigService->configureModule(
                    $target,
                    $moduleKey,
                    $config['enabled'],
                    $config['interval'],
                );
            }
            $target->update(['is_preset_customized' => true]);
            $count++;
        }

        return $count;
    }
}
