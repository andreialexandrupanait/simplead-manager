<?php

namespace Tests\Feature;

use App\Livewire\Settings\ProfileSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->call('deleteAccount')
            ->assertRedirect('/login');

        $this->assertNull($user->fresh());
    }

    public function test_password_can_be_changed(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('changePassword')
            ->assertHasNoErrors();
    }

    public function test_correct_password_must_be_provided_to_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        Livewire::actingAs($user)
            ->test(ProfileSettings::class)
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('changePassword')
            ->assertHasErrors('currentPassword');
    }
}
