<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\ProfileSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->admin()->create([
            'name' => 'Original Name',
            'timezone' => 'UTC',
        ]);
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_profile(): void
    {
        Livewire::actingAs($this->user)
            ->test(ProfileSettings::class)
            ->assertOk()
            ->assertSet('name', 'Original Name')
            ->assertSet('timezone', 'UTC');
    }

    // ─── saveProfile() ────────────────────────────────────────────────

    #[Test]
    public function user_can_update_name(): void
    {
        Livewire::actingAs($this->user)
            ->test(ProfileSettings::class)
            ->set('name', 'Updated Name')
            ->call('saveProfile')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Updated Name',
        ]);
    }

    #[Test]
    public function user_can_update_timezone(): void
    {
        Livewire::actingAs($this->user)
            ->test(ProfileSettings::class)
            ->set('timezone', 'Europe/Bucharest')
            ->call('saveProfile')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'timezone' => 'Europe/Bucharest',
        ]);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function validates_required_fields(): void
    {
        Livewire::actingAs($this->user)
            ->test(ProfileSettings::class)
            ->set('name', '')
            ->call('saveProfile')
            ->assertHasErrors(['name']);
    }

    #[Test]
    public function validates_invalid_timezone(): void
    {
        Livewire::actingAs($this->user)
            ->test(ProfileSettings::class)
            ->set('timezone', 'Not/A/Timezone')
            ->call('saveProfile')
            ->assertHasErrors(['timezone']);
    }

    #[Test]
    public function validates_unique_email_on_save(): void
    {
        $otherUser = User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($this->user)
            ->test(ProfileSettings::class)
            ->set('email', 'taken@example.com')
            ->call('saveProfile')
            ->assertHasErrors(['email']);
    }

    // ─── changePassword() ─────────────────────────────────────────────

    #[Test]
    public function user_can_change_password(): void
    {
        $user = User::factory()->admin()->create([
            'password' => bcrypt('OldPassword1!'),
        ]);

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('currentPassword', 'OldPassword1!')
            ->set('newPassword', 'NewPassword2@')
            ->set('newPasswordConfirmation', 'NewPassword2@')
            ->call('changePassword')
            ->assertDispatched('notify')
            ->assertHasNoErrors();
    }

    #[Test]
    public function wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->admin()->create([
            'password' => bcrypt('CorrectPassword1!'),
        ]);

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('currentPassword', 'WrongPassword!')
            ->set('newPassword', 'NewPassword2@')
            ->set('newPasswordConfirmation', 'NewPassword2@')
            ->call('changePassword')
            ->assertHasErrors(['currentPassword']);
    }
}
