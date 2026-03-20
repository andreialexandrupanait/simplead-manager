<?php

namespace App\Services;

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
     */
    public function copySecuritySettings(Site $source, Collection $targets): int
    {
        $categories = array_keys(SecuritySettingsService::VALID_SETTING_KEYS);
        $settings = $source->securitySettings()
            ->whereIn('category', $categories)
            ->get();

        if ($settings->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($targets as $target) {
            foreach ($settings as $setting) {
                $this->securityService->applySetting(
                    $target,
                    $setting->category->value ?? $setting->category,
                    $setting->setting_key,
                    $setting->setting_value,
                    $setting->is_enabled,
                );
            }
            $count++;
        }

        return $count;
    }

    /**
     * Copy tweak settings from source site to target sites.
     */
    public function copyTweakSettings(Site $source, Collection $targets): int
    {
        $categories = SiteTweaksSettingsService::TWEAK_CATEGORIES;
        $settings = $source->securitySettings()
            ->whereIn('category', $categories)
            ->get()
            ->groupBy(fn ($s) => $s->category->value ?? $s->category);

        if ($settings->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($targets as $target) {
            foreach ($settings as $category => $categorySettings) {
                $mapped = [];
                foreach ($categorySettings as $s) {
                    $mapped[$s->setting_key] = [
                        'enabled' => $s->is_enabled,
                        'value' => $s->setting_value,
                    ];
                }
                $this->tweaksService->applyMultiple($target, $category, $mapped);
            }
            $count++;
        }

        return $count;
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
