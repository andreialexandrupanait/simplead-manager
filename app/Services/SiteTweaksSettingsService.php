<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\PushSiteTweaksSettings;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Collection;

class SiteTweaksSettingsService
{
    public const VALID_SETTING_KEYS = [
        'performance' => [
            'heartbeat_control',
            'revisions_control',
            'image_upload_control',
            'disable_generator_tag',
            'disable_wlw_manifest',
            'disable_rsd_link',
            'disable_shortlinks',
            'disable_emojis',
            'disable_dashicons',
            'disable_jquery_migrate',
            'disable_lazy_load',
            'disable_block_widgets',
            'disable_self_pingbacks',
            'disable_rest_api_links',
            'disable_dns_prefetch',
            'disable_xml_sitemap',
            'disable_google_fonts',
            'disable_global_styles',
            'optimize_woocommerce',
        ],
        'site_control' => [
            'disable_all_updates',
            'disable_comments',
            'disable_feeds',
            'disable_embeds',
            'redirect_404',
            'disable_gutenberg',
            'disable_author_archives',
        ],
        'admin_ux' => [
            'clean_admin_bar',
            'hide_admin_notices',
            'disable_dashboard_widgets',
            'custom_admin_css',
            'custom_frontend_css',
            'hide_admin_bar',
            'admin_menu_organizer',
            'custom_admin_footer',
            'wider_admin_menu',
        ],
        'content_media' => [
            'content_duplication',
            'media_replacement',
            'svg_upload',
            'avif_upload',
            'external_permalinks',
            'open_external_links_new_tab',
            'media_visibility_control',
            'auto_publish_missed_schedule',
            'content_order',
        ],
        'email' => [
            'custom_email_from',
            'postmark_config',
            'email_logging',
        ],
    ];

    /**
     * Categories managed by this service (the "tweak" categories).
     */
    public const TWEAK_CATEGORIES = [
        'performance',
        'site_control',
        'admin_ux',
        'content_media',
        'email',
    ];

    public function getSettingsForSite(Site $site): Collection
    {
        return $site->securitySettings()
            ->whereIn('category', self::TWEAK_CATEGORIES)
            ->orderBy('category')
            ->orderBy('setting_key')
            ->get()
            ->groupBy('category');
    }

    public function getSettingsForCategory(Site $site, string $category): Collection
    {
        return $site->securitySettings()
            ->where('category', $category)
            ->get()
            ->keyBy('setting_key');
    }

    public function isValidSetting(string $category, string $key): bool
    {
        return isset(self::VALID_SETTING_KEYS[$category])
            && in_array($key, self::VALID_SETTING_KEYS[$category], true);
    }

    public function applySetting(Site $site, string $category, string $key, mixed $value, bool $enabled): SecuritySetting
    {
        if (! $this->isValidSetting($category, $key)) {
            throw new \InvalidArgumentException("Invalid tweak setting: {$category}/{$key}");
        }

        return SecuritySetting::updateOrCreate(
            ['site_id' => $site->id, 'category' => $category, 'setting_key' => $key],
            ['setting_value' => $value, 'is_enabled' => $enabled, 'failed_at' => null, 'failure_reason' => null],
        );
    }

    public function applyMultiple(Site $site, string $category, array $settings): void
    {
        foreach ($settings as $key => $config) {
            $enabled = $config['enabled'] ?? false;
            $value = $config['value'] ?? $enabled;

            $this->applySetting($site, $category, $key, $value, $enabled);
        }

        $this->pushToPlugin($site);
    }

    public function syncSettingsFromPlugin(Site $site, array $reportedSettings): void
    {
        foreach ($reportedSettings as $item) {
            if (! $this->isValidSetting($item['category'], $item['key'])) {
                continue;
            }

            $setting = SecuritySetting::where('site_id', $site->id)
                ->where('category', $item['category'])
                ->where('setting_key', $item['key'])
                ->first();

            if (! $setting) {
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
    }

    /**
     * Dispatch a job to push tweak settings to the WordPress plugin.
     * Uses a 5-second delay to consolidate rapid changes.
     */
    public function pushToPlugin(Site $site): void
    {
        PushSiteTweaksSettings::dispatch($site)->delay(now()->addSeconds(5));
    }
}
