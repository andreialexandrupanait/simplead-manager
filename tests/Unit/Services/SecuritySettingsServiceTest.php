<?php

namespace Tests\Unit\Services;

use App\Enums\SecurityCommandStatus;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecuritySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecuritySettingsService $service;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(SecuritySettingsService::class);
        $this->site = Site::factory()->create();
    }

    #[Test]
    public function is_valid_setting_returns_true_for_valid_keys(): void
    {
        $this->assertTrue($this->service->isValidSetting('hardening', 'disable_theme_editor'));
        $this->assertTrue($this->service->isValidSetting('login', 'brute_force_protection'));
        $this->assertTrue($this->service->isValidSetting('htaccess', 'firewall_enabled'));
    }

    #[Test]
    public function is_valid_setting_returns_false_for_invalid_keys(): void
    {
        $this->assertFalse($this->service->isValidSetting('hardening', 'nonexistent_key'));
        $this->assertFalse($this->service->isValidSetting('invalid_category', 'disable_theme_editor'));
    }

    #[Test]
    public function apply_setting_creates_setting_and_command(): void
    {
        $setting = $this->service->applySetting(
            $this->site,
            'hardening',
            'disable_theme_editor',
            true,
            true,
        );

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('security_commands', [
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'action' => 'disable_theme_editor',
            'status' => SecurityCommandStatus::Pending->value,
        ]);
    }

    #[Test]
    public function apply_setting_throws_for_invalid_setting(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->applySetting(
            $this->site,
            'hardening',
            'nonexistent_key',
            true,
            true,
        );
    }

    #[Test]
    public function apply_setting_updates_existing_setting(): void
    {
        $this->service->applySetting($this->site, 'hardening', 'disable_theme_editor', true, true);
        $this->service->applySetting($this->site, 'hardening', 'disable_theme_editor', false, false);

        $settings = SecuritySetting::where('site_id', $this->site->id)
            ->where('setting_key', 'disable_theme_editor')
            ->get();

        $this->assertCount(1, $settings);
        $this->assertFalse($settings->first()->is_enabled);
    }

    #[Test]
    public function get_security_score_calculates_correctly(): void
    {
        // Apply two known settings
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => true,
            'is_enabled' => true,
            'applied_at' => now(),
        ]);

        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'brute_force_protection',
            'setting_value' => true,
            'is_enabled' => true,
            'applied_at' => now(),
        ]);

        $score = $this->service->getSecurityScore($this->site);

        // disable_theme_editor = 10, brute_force_protection = 15
        $this->assertEquals(25, $score);
    }

    #[Test]
    public function get_security_score_excludes_failed_settings(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => true,
            'is_enabled' => true,
            'applied_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'File not writable',
        ]);

        $score = $this->service->getSecurityScore($this->site);

        $this->assertEquals(0, $score);
    }

    #[Test]
    public function get_security_score_excludes_disabled_settings(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => true,
            'is_enabled' => false,
            'applied_at' => now(),
        ]);

        $score = $this->service->getSecurityScore($this->site);

        $this->assertEquals(0, $score);
    }

    #[Test]
    public function get_security_score_caps_at_100(): void
    {
        // Apply ALL scored settings
        foreach (SecuritySettingsService::SCORE_WEIGHTS as $key => $weight) {
            $category = $this->findCategoryForKey($key);
            SecuritySetting::create([
                'site_id' => $this->site->id,
                'category' => $category,
                'setting_key' => $key,
                'setting_value' => true,
                'is_enabled' => true,
                'applied_at' => now(),
            ]);
        }

        $score = $this->service->getSecurityScore($this->site);

        $this->assertLessThanOrEqual(100, $score);
    }

    #[Test]
    public function sync_settings_from_agent_updates_applied(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => true,
            'is_enabled' => true,
        ]);

        $this->service->syncSettingsFromAgent($this->site, [
            ['category' => 'hardening', 'key' => 'disable_theme_editor', 'applied' => true],
        ]);

        $setting = SecuritySetting::where('site_id', $this->site->id)
            ->where('setting_key', 'disable_theme_editor')
            ->first();

        $this->assertNotNull($setting->applied_at);
    }

    #[Test]
    public function sync_settings_from_agent_updates_failed(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => true,
            'is_enabled' => true,
        ]);

        $this->service->syncSettingsFromAgent($this->site, [
            ['category' => 'hardening', 'key' => 'disable_theme_editor', 'failed' => true, 'reason' => 'Permission denied'],
        ]);

        $setting = SecuritySetting::where('site_id', $this->site->id)
            ->where('setting_key', 'disable_theme_editor')
            ->first();

        $this->assertNotNull($setting->failed_at);
        $this->assertEquals('Permission denied', $setting->failure_reason);
    }

    #[Test]
    public function sync_settings_from_agent_ignores_invalid_keys(): void
    {
        $this->service->syncSettingsFromAgent($this->site, [
            ['category' => 'hardening', 'key' => 'invalid_key', 'applied' => true],
        ]);

        // No exception — just silently ignored
        $this->assertDatabaseCount('security_settings', 0);
    }

    private function findCategoryForKey(string $key): string
    {
        foreach (SecuritySettingsService::VALID_SETTING_KEYS as $category => $keys) {
            if (in_array($key, $keys, true)) {
                return $category;
            }
        }

        return 'hardening';
    }
}
