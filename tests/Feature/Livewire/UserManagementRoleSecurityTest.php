<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\UserManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-58: role mutations in UserManagement must (a) reject any role string that
 * is not a real UserRole enum case, and (b) enforce admin authorization inside
 * the component — not rely on route middleware alone.
 */
class UserManagementRoleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_role_string_is_rejected_with_no_db_change(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('updateRole', $target->id, 'superadmin')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::Viewer, $target->fresh()->role);
    }

    public function test_non_admin_cannot_mutate_roles_even_reaching_the_method(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $target = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($manager)
            ->test(UserManagement::class)
            ->call('updateRole', $target->id, 'admin')
            ->assertForbidden();

        $this->assertSame(UserRole::Viewer, $target->fresh()->role);
    }

    public function test_admin_can_set_a_valid_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('updateRole', $target->id, 'manager')
            ->assertHasNoErrors();

        $this->assertSame(UserRole::Manager, $target->fresh()->role);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('updateRole', $admin->id, 'viewer');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }
}
