<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Security\SecurityHardening;
use App\Models\Site;
use App\Models\User;
use App\Services\SecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_security_hardening(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityHardening::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── loadCurrentState() ───────────────────────────────────────────

    #[Test]
    public function user_can_view_security_settings(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityHardening::class, ['site' => $this->site])
            ->assertOk();

        // All hardening toggle keys must be present after mount
        foreach (SecuritySettingsService::VALID_SETTING_KEYS['hardening'] as $key) {
            $this->assertArrayHasKey($key, $component->get('hardeningToggles'));
        }

        // All htaccess toggle keys must be present after mount
        foreach (SecuritySettingsService::VALID_SETTING_KEYS['htaccess'] as $key) {
            $this->assertArrayHasKey($key, $component->get('htaccessToggles'));
        }
    }

    // ─── toggleSetting() ──────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_a_hardening_setting(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityHardening::class, ['site' => $this->site]);

        $initialValue = $component->get('hardeningToggles')['disable_theme_editor'];

        $component->call('toggleSetting', 'hardening', 'disable_theme_editor');

        $updatedToggles = $component->get('hardeningToggles');
        $this->assertNotEquals($initialValue, $updatedToggles['disable_theme_editor']);
        $this->assertTrue($component->get('isDirty'));
    }

    // ─── enableRecommended() ──────────────────────────────────────────

    #[Test]
    public function enable_recommended_sets_all_recommended_settings(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityHardening::class, ['site' => $this->site])
            ->call('enableRecommended');

        $hardeningToggles = $component->get('hardeningToggles');
        $htaccessToggles = $component->get('htaccessToggles');

        foreach (SecurityHardening::RECOMMENDED_HARDENING as $key) {
            $this->assertTrue($hardeningToggles[$key], "Expected hardening key '{$key}' to be enabled.");
        }

        foreach (SecurityHardening::RECOMMENDED_HTACCESS as $key) {
            $this->assertTrue($htaccessToggles[$key], "Expected htaccess key '{$key}' to be enabled.");
        }

        $this->assertTrue($component->get('isDirty'));
        $this->assertTrue($component->instance()->allRecommendedEnabled);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_hardening(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityHardening::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
