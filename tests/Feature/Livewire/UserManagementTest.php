<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\UserManagement;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_user_management_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->assertOk();
    }

    #[Test]
    public function page_lists_all_users(): void
    {
        User::factory()->manager()->create(['name' => 'Manager One']);
        User::factory()->viewer()->create(['name' => 'Viewer One']);

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->assertOk();
    }

    // ─── sendInvitation() ─────────────────────────────────────────────

    #[Test]
    public function admin_can_send_invitation(): void
    {
        Mail::fake();

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->set('inviteEmail', 'newuser@example.com')
            ->set('inviteRole', 'manager')
            ->call('sendInvitation');

        $this->assertDatabaseHas('invitations', [
            'email' => 'newuser@example.com',
            'role' => 'manager',
        ]);
    }

    #[Test]
    public function invitation_requires_valid_email(): void
    {
        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->set('inviteEmail', 'not-an-email')
            ->set('inviteRole', 'manager')
            ->call('sendInvitation')
            ->assertHasErrors(['inviteEmail']);
    }

    #[Test]
    public function cannot_invite_already_existing_user(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->set('inviteEmail', 'existing@example.com')
            ->set('inviteRole', 'manager')
            ->call('sendInvitation')
            ->assertHasErrors(['inviteEmail']);
    }

    #[Test]
    public function cannot_send_duplicate_pending_invitation(): void
    {
        Invitation::create([
            'email' => 'pending@example.com',
            'role' => 'manager',
            'token' => 'some-token-abc',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addHours(48),
            'accepted_at' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->set('inviteEmail', 'pending@example.com')
            ->set('inviteRole', 'viewer')
            ->call('sendInvitation')
            ->assertHasErrors(['inviteEmail']);
    }

    // ─── revokeInvitation() ───────────────────────────────────────────

    #[Test]
    public function admin_can_revoke_pending_invitation(): void
    {
        $invitation = Invitation::create([
            'email' => 'revoke@example.com',
            'role' => 'manager',
            'token' => 'revoke-token-xyz',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addHours(48),
            'accepted_at' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->call('revokeInvitation', $invitation->id);

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
    }

    // ─── deleteUser() ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_another_user(): void
    {
        $manager = User::factory()->manager()->create();

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->call('confirmDeleteUser', $manager->id)
            ->call('deleteUser');

        $this->assertDatabaseMissing('users', ['id' => $manager->id]);
    }

    #[Test]
    public function admin_cannot_delete_themselves(): void
    {
        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->call('confirmDeleteUser', $this->admin->id)
            ->call('deleteUser');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    // ─── updateRole() ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_update_another_users_role(): void
    {
        $manager = User::factory()->manager()->create();

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->call('updateRole', $manager->id, 'viewer');

        $this->assertDatabaseHas('users', [
            'id' => $manager->id,
            'role' => 'viewer',
        ]);
    }

    #[Test]
    public function admin_cannot_change_their_own_role(): void
    {
        $originalRole = $this->admin->role->value;

        Livewire::actingAs($this->admin)
            ->test(UserManagement::class)
            ->call('updateRole', $this->admin->id, 'viewer');

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'role' => $originalRole,
        ]);
    }
}
