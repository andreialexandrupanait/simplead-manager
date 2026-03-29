<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\PushSecuritySettings;
use App\Livewire\Sites\Detail\Security\SecurityLogin;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityLoginTest extends TestCase
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
    public function user_can_view_login_protection_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->assertOk()
            ->assertSet('maxAttempts', 5)
            ->assertSet('windowMinutes', 10)
            ->assertSet('blockDurationMinutes', 60);
    }

    #[Test]
    public function existing_login_settings_are_loaded_on_mount(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'brute_force_protection',
            'setting_value' => [
                'max_attempts' => 3,
                'window_minutes' => 5,
                'block_duration_minutes' => 120,
            ],
            'is_enabled' => true,
        ]);

        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'custom_login_url',
            'setting_value' => ['slug' => 'my-login'],
            'is_enabled' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->assertSet('maxAttempts', 3)
            ->assertSet('windowMinutes', 5)
            ->assertSet('blockDurationMinutes', 120)
            ->assertSet('loginSlug', 'my-login');
    }

    // ─── save() ───────────────────────────────────────────────────────

    #[Test]
    public function user_can_save_brute_force_protection_settings(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('maxAttempts', 3)
            ->set('windowMinutes', 15)
            ->set('blockDurationMinutes', 180)
            ->set('loginSlug', '')
            ->call('save');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'brute_force_protection',
            'is_enabled' => true,
        ]);

        Queue::assertPushed(PushSecuritySettings::class);
    }

    #[Test]
    public function user_can_configure_custom_login_url(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('maxAttempts', 5)
            ->set('windowMinutes', 10)
            ->set('blockDurationMinutes', 60)
            ->set('loginSlug', 'secret-login')
            ->call('save');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'custom_login_url',
            'is_enabled' => true,
        ]);
    }

    #[Test]
    public function empty_login_slug_disables_custom_login_url(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('maxAttempts', 5)
            ->set('windowMinutes', 10)
            ->set('blockDurationMinutes', 60)
            ->set('loginSlug', '')
            ->call('save');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'custom_login_url',
            'is_enabled' => false,
        ]);
    }

    #[Test]
    public function saving_login_settings_marks_component_as_not_dirty(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('maxAttempts', 3)
            ->set('windowMinutes', 5)
            ->set('blockDurationMinutes', 30)
            ->call('save');

        $component->assertSet('isDirty', false);
    }

    // ─── toggleTwoFactor() ────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_two_factor_authentication(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->assertSet('twoFactorEnabled', false)
            ->call('toggleTwoFactor');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'login',
            'setting_key' => 'two_factor_auth',
            'is_enabled' => true,
        ]);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function save_requires_max_attempts_within_range(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('maxAttempts', 0)
            ->call('save')
            ->assertHasErrors(['maxAttempts']);
    }

    #[Test]
    public function save_requires_window_minutes_within_range(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('windowMinutes', 0)
            ->call('save')
            ->assertHasErrors(['windowMinutes']);
    }

    #[Test]
    public function save_rejects_login_slug_with_special_characters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityLogin::class, ['site' => $this->site])
            ->set('loginSlug', 'my login page!!')
            ->call('save')
            ->assertHasErrors(['loginSlug']);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_login_settings(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityLogin::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
