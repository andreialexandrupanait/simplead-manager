<?php

namespace App\Services;

use App\Jobs\PushSecuritySettings;
use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Collection;

class SecuritySettingsService
{
    public function __construct(
        private SecurityCommandService $commandService,
    ) {}

    public const VALID_SETTING_KEYS = [
        'hardening' => [
            'disable_theme_editor',
            'disable_user_enumeration',
            'hide_wp_version',
            'restrict_xmlrpc',
            'security_headers',
            'block_application_passwords',
            'restrict_rest_api',
        ],
        'htaccess' => [
            'block_default_files',
            'block_readme_access',
            'block_debug_log',
            'disable_directory_listing',
            'firewall_enabled',
        ],
        'login' => [
            'brute_force_protection',
            'custom_login_url',
            'two_factor_auth',
        ],
        'captcha' => [
            'captcha_config',
        ],
        'ip_management' => [
            'firewall_config',
        ],
        'activity_log' => [
            'activity_log_config',
        ],
    ];

    public const SCORE_WEIGHTS = [
        'brute_force_protection' => 15,
        'disable_theme_editor' => 10,
        'restrict_xmlrpc' => 10,
        'security_headers' => 10,
        'disable_user_enumeration' => 8,
        'block_default_files' => 7,
        'hide_wp_version' => 5,
        'block_readme_access' => 5,
        'block_debug_log' => 5,
        'disable_directory_listing' => 5,
        'block_application_passwords' => 5,
        'restrict_rest_api' => 5,
        'firewall_config' => 5,
        'activity_log_config' => 5,
    ];

    public function getSettingsForSite(Site $site): Collection
    {
        return $site->securitySettings()
            ->orderBy('category')
            ->orderBy('setting_key')
            ->get()
            ->groupBy('category');
    }

    public function isValidSetting(string $category, string $key): bool
    {
        return isset(self::VALID_SETTING_KEYS[$category])
            && in_array($key, self::VALID_SETTING_KEYS[$category], true);
    }

    public function applySetting(Site $site, string $category, string $key, mixed $value, bool $enabled): SecuritySetting
    {
        if (!$this->isValidSetting($category, $key)) {
            throw new \InvalidArgumentException("Invalid security setting: {$category}/{$key}");
        }

        $setting = SecuritySetting::updateOrCreate(
            ['site_id' => $site->id, 'category' => $category, 'setting_key' => $key],
            ['setting_value' => $value, 'is_enabled' => $enabled, 'failed_at' => null, 'failure_reason' => null],
        );

        $this->commandService->createCommand($site, $category, $key, [
            'value' => $value,
            'enabled' => $enabled,
        ]);

        // Push settings to the WordPress plugin via REST API
        $this->pushToPlugin($site);

        return $setting;
    }

    public function applyPreset(SecurityPreset $preset, Collection $sites): void
    {
        $settings = $preset->settings;

        foreach ($sites as $site) {
            foreach ($settings as $category => $categorySettings) {
                foreach ($categorySettings as $key => $config) {
                    $this->applySetting(
                        $site,
                        $category,
                        $key,
                        $config['value'] ?? null,
                        $config['enabled'] ?? false,
                    );
                }
            }

            // Update pivot
            $site->securityPresets()->syncWithoutDetaching([
                $preset->id => [
                    'applied_at' => now(),
                    'applied_version' => $preset->version,
                ],
            ]);
        }
    }

    public function syncSettingsFromAgent(Site $site, array $reportedSettings): void
    {
        foreach ($reportedSettings as $item) {
            if (!$this->isValidSetting($item['category'], $item['key'])) {
                continue;
            }

            $setting = SecuritySetting::where('site_id', $site->id)
                ->where('category', $item['category'])
                ->where('setting_key', $item['key'])
                ->first();

            if (!$setting) {
                continue;
            }

            if ($item['applied'] ?? false) {
                $setting->update([
                    'applied_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);
            } elseif ($item['failed'] ?? false) {
                $setting->update([
                    'failed_at' => now(),
                    'failure_reason' => $item['reason'] ?? 'Unknown error',
                ]);
            }
        }

        $site->update(['security_hardening_score' => $this->getSecurityScore($site)]);
    }

    public function getSecurityScore(Site $site): int
    {
        $appliedSettings = SecuritySetting::where('site_id', $site->id)
            ->where('is_enabled', true)
            ->whereNotNull('applied_at')
            ->whereNull('failed_at')
            ->pluck('setting_key')
            ->toArray();

        $score = 0;
        foreach (self::SCORE_WEIGHTS as $key => $weight) {
            if (in_array($key, $appliedSettings)) {
                $score += $weight;
            }
        }

        return min(100, $score);
    }

    /**
     * Dispatch a job to push security settings to the WordPress plugin.
     * Uses a 5-second delay to consolidate rapid changes.
     */
    public function pushToPlugin(Site $site): void
    {
        PushSecuritySettings::dispatch($site)->delay(now()->addSeconds(1));
    }

    public function recalculateAllScores(): void
    {
        Site::whereHas('securitySettings')->chunk(100, function ($sites) {
            foreach ($sites as $site) {
                $site->update(['security_hardening_score' => $this->getSecurityScore($site)]);
            }
        });
    }
}
