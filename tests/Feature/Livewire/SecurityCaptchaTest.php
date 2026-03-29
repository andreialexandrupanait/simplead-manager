<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\PushSecuritySettings;
use App\Livewire\Sites\Detail\Security\SecurityCaptcha;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityCaptchaTest extends TestCase
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
    public function user_can_view_captcha_settings_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->assertOk()
            ->assertSet('provider', 'none');
    }

    #[Test]
    public function existing_captcha_settings_are_loaded_on_mount(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'captcha',
            'setting_key' => 'captcha_config',
            'setting_value' => [
                'provider' => 'recaptcha_v3',
                'site_key' => 'test-site-key',
                'forms' => [
                    'login' => true,
                    'register' => false,
                    'comments' => true,
                    'reset_password' => true,
                ],
            ],
            'is_enabled' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->assertSet('provider', 'recaptcha_v3')
            ->assertSet('enableRegister', false)
            ->assertSet('enableComments', true)
            ->assertSet('hasExistingKeys', true);
    }

    // ─── save() ───────────────────────────────────────────────────────

    #[Test]
    public function user_can_save_captcha_settings_with_none_provider(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->set('provider', 'none')
            ->call('save');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'captcha',
            'setting_key' => 'captcha_config',
            'is_enabled' => false,
        ]);
    }

    #[Test]
    public function user_can_save_captcha_settings_with_recaptcha_provider(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->set('provider', 'recaptcha_v2')
            ->set('siteKey', 'my-site-key')
            ->set('secretKey', 'my-secret-key')
            ->set('enableLogin', true)
            ->set('enableRegister', true)
            ->set('enableComments', false)
            ->call('save');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'captcha',
            'setting_key' => 'captcha_config',
            'is_enabled' => true,
        ]);

        Queue::assertPushed(PushSecuritySettings::class);
    }

    #[Test]
    public function saving_captcha_dispatches_push_job(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->set('provider', 'hcaptcha')
            ->set('siteKey', 'hcaptcha-site-key')
            ->set('secretKey', 'hcaptcha-secret')
            ->call('save');

        Queue::assertPushed(PushSecuritySettings::class, function (PushSecuritySettings $job) {
            return $job->site->id === $this->site->id;
        });
    }

    #[Test]
    public function save_clears_entered_keys_from_component_state(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->set('provider', 'turnstile')
            ->set('siteKey', 'turnstile-site-key')
            ->set('secretKey', 'turnstile-secret')
            ->call('save');

        $component->assertSet('siteKey', '');
        $component->assertSet('secretKey', '');
        $component->assertSet('hasExistingKeys', true);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function save_requires_valid_provider(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->set('provider', 'invalid_provider')
            ->call('save')
            ->assertHasErrors(['provider']);
    }

    #[Test]
    public function save_requires_site_key_when_provider_is_set_and_no_existing_keys(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityCaptcha::class, ['site' => $this->site])
            ->set('provider', 'recaptcha_v2')
            ->set('siteKey', '')
            ->set('secretKey', '')
            ->call('save')
            ->assertHasErrors(['siteKey', 'secretKey']);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_captcha(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityCaptcha::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
