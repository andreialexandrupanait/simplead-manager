<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\Security\SecurityLogin;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The 2FA control used to be a dead "Coming Soon" badge — toggleTwoFactor()
 * existed but nothing called it, and the connector had no implementation.
 * Now it is a real setting pushed through the standard security pipeline.
 */
class SecurityLoginTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_persists_the_full_two_factor_payload(): void
    {
        Queue::fake(); // intercept PushSecuritySettings

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        Livewire::actingAs($manager)
            ->test(SecurityLogin::class, ['site' => $site])
            ->call('toggleTwoFactor')
            ->assertSet('twoFactorEnabled', true);

        $setting = SecuritySetting::where('site_id', $site->id)
            ->where('setting_key', 'two_factor_auth')->sole();

        $this->assertTrue($setting->is_enabled);
        $this->assertSame(['administrator', 'editor'], $setting->setting_value['roles']);
        $this->assertSame('open', $setting->setting_value['fail_mode']);

        Queue::assertPushed(\App\Jobs\PushSecuritySettings::class);
    }

    public function test_role_and_fail_mode_options_are_saved(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        Livewire::actingAs($manager)
            ->test(SecurityLogin::class, ['site' => $site])
            ->call('toggleTwoFactor')
            ->set('twoFactorRoles', ['administrator'])
            ->set('twoFactorFailMode', 'closed')
            ->call('saveTwoFactorOptions');

        $setting = SecuritySetting::where('site_id', $site->id)
            ->where('setting_key', 'two_factor_auth')->sole();

        $this->assertSame(['administrator'], $setting->setting_value['roles']);
        $this->assertSame('closed', $setting->setting_value['fail_mode']);
    }

    public function test_invalid_role_is_rejected(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        Livewire::actingAs($manager)
            ->test(SecurityLogin::class, ['site' => $site])
            ->call('toggleTwoFactor')
            ->set('twoFactorRoles', ['super-hacker'])
            ->call('saveTwoFactorOptions')
            ->assertHasErrors('twoFactorRoles.0');
    }

    public function test_viewer_cannot_toggle_two_factor(): void
    {
        Queue::fake();

        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id, 'is_connected' => true]);

        Livewire::actingAs($viewer)
            ->test(SecurityLogin::class, ['site' => $site])
            ->call('toggleTwoFactor')
            ->assertForbidden();

        $this->assertDatabaseMissing('security_settings', ['setting_key' => 'two_factor_auth']);
    }
}
