<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\MaintenancePlan;
use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\BulkSettingsCopyService;
use App\Services\MaintenancePlanService;
use App\Services\SecurityPresetService;
use App\Services\SecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression coverage for P0-11 / P1-63: plan snapshots, presets, and bulk
 * copy must never propagate site-specific/credential security settings across
 * sites. One canonical whitelist (SecuritySettingsService::BULK_SAFE_SETTING_KEYS)
 * is enforced at BOTH snapshot time and apply time.
 */
class BulkSafeWhitelistTest extends TestCase
{
    use RefreshDatabase;

    /** The four categories/keys that must never cross sites. */
    private const DANGEROUS = [
        ['login', 'custom_login_url', ['slug' => 'secret-source-login']],
        ['login', 'two_factor_auth', ['method' => 'totp']],
        ['captcha', 'captcha_config', ['secret_key' => 'ENCRYPTED-SOURCE-SECRET']],
        ['ip_management', 'firewall_config', ['blocklist' => ['1.2.3.4']]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Site::created() dispatches FetchSiteFavicon (outbound HTTP).
        Queue::fake();
    }

    private function setting(Site $site, string $category, string $key, mixed $value, bool $enabled = true): SecuritySetting
    {
        return SecuritySetting::create([
            'site_id' => $site->id,
            'category' => $category,
            'setting_key' => $key,
            'setting_value' => $value,
            'is_enabled' => $enabled,
        ]);
    }

    public function test_whitelist_excludes_all_dangerous_keys_and_includes_safe_ones(): void
    {
        $service = app(SecuritySettingsService::class);

        $this->assertTrue($service->isBulkSafeSetting('hardening', 'disable_theme_editor'));
        $this->assertTrue($service->isBulkSafeSetting('htaccess', 'firewall_enabled'));
        $this->assertTrue($service->isBulkSafeSetting('login', 'brute_force_protection'));

        foreach (self::DANGEROUS as [$category, $key]) {
            $this->assertFalse(
                $service->isBulkSafeSetting($category, $key),
                "{$category}/{$key} must NOT be bulk-safe",
            );
        }
    }

    public function test_plan_snapshot_never_captures_dangerous_keys(): void
    {
        $source = Site::factory()->create();
        $this->setting($source, 'hardening', 'disable_theme_editor', ['enabled' => true]);
        foreach (self::DANGEROUS as [$category, $key, $value]) {
            $this->setting($source, $category, $key, $value);
        }

        $plan = app(MaintenancePlanService::class)->createFromSite($source, 'Snap', '', ['security']);

        $this->assertArrayHasKey('hardening', $plan->security_settings);
        $this->assertArrayHasKey('disable_theme_editor', $plan->security_settings['hardening']);
        $this->assertArrayNotHasKey('captcha', $plan->security_settings);
        $this->assertArrayNotHasKey('ip_management', $plan->security_settings);
        $this->assertArrayNotHasKey('custom_login_url', $plan->security_settings['login'] ?? []);
        $this->assertArrayNotHasKey('two_factor_auth', $plan->security_settings['login'] ?? []);
    }

    /**
     * The core P0-11 acceptance: an EXISTING plan that already contains
     * dangerous keys must be filtered on apply and must NOT overwrite the
     * target site's own login URL / 2FA / CAPTCHA secret / firewall.
     */
    public function test_existing_dangerous_plan_keys_are_ignored_on_apply(): void
    {
        $target = Site::factory()->create();

        // Target already has its OWN site-specific settings.
        $this->setting($target, 'login', 'custom_login_url', ['slug' => 'target-own-login']);
        $this->setting($target, 'captcha', 'captcha_config', ['secret_key' => 'TARGET-OWN-SECRET']);

        // A legacy plan JSON that still contains dangerous keys from Site A.
        $plan = MaintenancePlan::create([
            'name' => 'Legacy',
            'include_modules' => false,
            'include_security' => true,
            'include_tweaks' => false,
            'security_settings' => [
                'hardening' => ['disable_theme_editor' => ['value' => ['enabled' => true], 'enabled' => true]],
                'login' => ['custom_login_url' => ['value' => ['slug' => 'secret-source-login'], 'enabled' => true]],
                'captcha' => ['captcha_config' => ['value' => ['secret_key' => 'SOURCE-SECRET'], 'enabled' => true]],
                'ip_management' => ['firewall_config' => ['value' => ['blocklist' => ['9.9.9.9']], 'enabled' => true]],
            ],
        ]);

        app(MaintenancePlanService::class)->applyToSites($plan, collect([$target]), ['security']);

        // Safe key applied.
        $this->assertDatabaseHas('security_settings', [
            'site_id' => $target->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
        ]);

        // Target's own site-specific settings untouched.
        $login = SecuritySetting::where('site_id', $target->id)->where('setting_key', 'custom_login_url')->first();
        $this->assertSame('target-own-login', $login->setting_value['slug']);

        $captcha = SecuritySetting::where('site_id', $target->id)->where('setting_key', 'captcha_config')->first();
        $this->assertSame('TARGET-OWN-SECRET', $captcha->setting_value['secret_key']);

        // No firewall row was created from the plan.
        $this->assertDatabaseMissing('security_settings', [
            'site_id' => $target->id,
            'setting_key' => 'firewall_config',
        ]);
    }

    public function test_preset_created_from_site_never_captures_captcha_secret(): void
    {
        $source = Site::factory()->create();
        $this->setting($source, 'hardening', 'restrict_xmlrpc', ['enabled' => true]);
        $this->setting($source, 'captcha', 'captcha_config', ['secret_key' => encrypt('SUPER-SECRET')]);
        $this->setting($source, 'login', 'custom_login_url', ['slug' => 'source-login']);

        $preset = app(SecurityPresetService::class)->createFromSite($source, 'Preset A');

        $this->assertArrayHasKey('hardening', $preset->settings);
        $this->assertArrayNotHasKey('captcha', $preset->settings);
        $this->assertArrayNotHasKey('custom_login_url', $preset->settings['login'] ?? []);
    }

    public function test_existing_dangerous_preset_keys_are_ignored_on_apply(): void
    {
        $target = Site::factory()->create();
        $this->setting($target, 'captcha', 'captcha_config', ['secret_key' => 'TARGET-OWN-SECRET']);

        // Legacy preset that still carries a source site's captcha secret.
        $preset = SecurityPreset::create([
            'name' => 'Legacy Preset',
            'version' => 1,
            'settings' => [
                'hardening' => ['hide_wp_version' => ['value' => ['enabled' => true], 'enabled' => true]],
                'captcha' => ['captcha_config' => ['value' => ['secret_key' => 'SOURCE-SECRET'], 'enabled' => true]],
            ],
        ]);

        app(SecuritySettingsService::class)->applyPreset($preset, collect([$target]));

        $captcha = SecuritySetting::where('site_id', $target->id)->where('setting_key', 'captcha_config')->first();
        $this->assertSame('TARGET-OWN-SECRET', $captcha->setting_value['secret_key']);

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $target->id,
            'setting_key' => 'hide_wp_version',
        ]);
    }

    public function test_bulk_copy_and_preset_apply_paths_skip_dangerous_keys(): void
    {
        $source = Site::factory()->create();
        $this->setting($source, 'hardening', 'security_headers', ['enabled' => true]);
        foreach (self::DANGEROUS as [$category, $key, $value]) {
            $this->setting($source, $category, $key, $value);
        }

        $target = Site::factory()->create();

        app(BulkSettingsCopyService::class)->copySecuritySettings($source, collect([$target]));

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $target->id,
            'setting_key' => 'security_headers',
        ]);
        foreach (self::DANGEROUS as [$category, $key]) {
            $this->assertDatabaseMissing('security_settings', [
                'site_id' => $target->id,
                'setting_key' => $key,
            ]);
        }
    }

    public function test_bulk_preset_apply_filters_dangerous_keys(): void
    {
        $target = Site::factory()->create();
        $preset = SecurityPreset::create([
            'name' => 'Bulk Preset',
            'version' => 1,
            'settings' => [
                'hardening' => ['restrict_rest_api' => ['value' => ['enabled' => true], 'enabled' => true]],
                'ip_management' => ['firewall_config' => ['value' => ['blocklist' => ['5.5.5.5']], 'enabled' => true]],
            ],
        ]);

        app(BulkSettingsCopyService::class)->applySecurityPreset($preset, new Collection([$target]));

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $target->id,
            'setting_key' => 'restrict_rest_api',
        ]);
        $this->assertDatabaseMissing('security_settings', [
            'site_id' => $target->id,
            'setting_key' => 'firewall_config',
        ]);
    }
}
