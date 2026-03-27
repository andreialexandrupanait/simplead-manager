<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;

class SecurityPresetService
{
    public function __construct(
        protected SecuritySettingsService $settingsService,
    ) {}

    public function createPreset(string $name, string $description, array $settings, ?int $createdBy = null): SecurityPreset
    {
        return SecurityPreset::create([
            'name' => $name,
            'description' => $description,
            'settings' => $settings,
            'created_by' => $createdBy,
        ]);
    }

    public function createFromSite(Site $site, string $name, string $description = ''): SecurityPreset
    {
        $settings = [];

        $siteSettings = SecuritySetting::where('site_id', $site->id)->get();

        foreach ($siteSettings as $setting) {
            $settings[$setting->category->value][$setting->setting_key] = [
                'value' => $setting->setting_value,
                'enabled' => $setting->is_enabled,
            ];
        }

        return SecurityPreset::create([
            'name' => $name,
            'description' => $description ?: "Snapshot from {$site->name}",
            'settings' => $settings,
            'created_by' => auth()->id(),
        ]);
    }

    public function getPresetDiff(SecurityPreset $preset, Site $site): array
    {
        $diff = [];
        $currentSettings = SecuritySetting::where('site_id', $site->id)
            ->get()
            ->keyBy(fn (SecuritySetting $s) => "{$s->category->value}.{$s->setting_key}");

        foreach ($preset->settings as $category => $categorySettings) {
            if (! is_array($categorySettings)) {
                continue;
            }

            foreach ($categorySettings as $key => $config) {
                if (! is_array($config)) {
                    continue;
                }

                $compositeKey = "{$category}.{$key}";
                $current = $currentSettings->get($compositeKey);

                $presetEnabled = $config['enabled'] ?? false;
                $currentEnabled = $current->is_enabled ?? false;

                if ($presetEnabled !== $currentEnabled || ($current && $current->setting_value !== ($config['value'] ?? null))) {
                    $diff[] = [
                        'category' => $category,
                        'key' => $key,
                        'current_enabled' => $currentEnabled,
                        'preset_enabled' => $presetEnabled,
                        'current_value' => $current->setting_value,
                        'preset_value' => $config['value'] ?? null,
                    ];
                }
            }
        }

        return $diff;
    }

    public function incrementVersion(SecurityPreset $preset): void
    {
        $preset->increment('version');
    }
}
