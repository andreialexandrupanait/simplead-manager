<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;

/**
 * SPEC §13 — the curated "Standard SimpleAD" WordPress preset package.
 *
 * Applies the 10 surfaced settings (config/site_presets.php) to a site through
 * the existing tweak pipeline (SiteTweaksSettingsService → SecuritySetting →
 * pushToPlugin), then pushes them to the connector. The `auto` group goes on
 * every site; the `woo` group only where WooCommerce is detected. Deviations
 * remain per-site: a later manual toggle on the Tweaks screens overrides this.
 */
class SitePresetService
{
    public function __construct(
        private readonly SiteTweaksSettingsService $tweaks,
    ) {}

    /** @return array{applied: int, woo: bool} */
    public function applyStandardPackage(Site $site, bool $push = true): array
    {
        $package = (array) config('site_presets.standard_simplead', []);
        $applied = 0;

        foreach ((array) ($package['auto'] ?? []) as $category => $settings) {
            $applied += $this->applyCategory($site, $category, $settings);
        }

        $hasWoo = $this->siteHasWoo($site);
        if ($hasWoo) {
            foreach ((array) ($package['woo'] ?? []) as $category => $settings) {
                $applied += $this->applyCategory($site, $category, $settings);
            }
        }

        if ($push && $applied > 0) {
            $this->tweaks->pushToPlugin($site);
        }

        return ['applied' => $applied, 'woo' => $hasWoo];
    }

    /**
     * @param  array<string, array{enabled: bool, essential?: bool}>  $settings
     */
    private function applyCategory(Site $site, string $category, array $settings): int
    {
        $count = 0;
        foreach ($settings as $key => $config) {
            if (! $this->tweaks->isValidSetting($category, $key)) {
                continue;
            }
            $enabled = (bool) ($config['enabled'] ?? true);
            $this->tweaks->applySetting($site, $category, $key, $enabled, $enabled);
            $count++;
        }

        return $count;
    }

    public function siteHasWoo(Site $site): bool
    {
        return $site->sitePlugins()
            ->where('slug', 'woocommerce')
            ->where('is_active', true)
            ->exists();
    }
}
