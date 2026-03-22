<?php

namespace Tests\Unit\Models;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_role_to_enum(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertInstanceOf(UserRole::class, $user->role);
        $this->assertSame(UserRole::Admin, $user->role);
    }

    #[Test]
    public function is_admin_returns_true_for_admins(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertTrue($user->isAdmin());
    }

    #[Test]
    public function is_admin_returns_false_for_non_admins(): void
    {
        $manager = User::factory()->manager()->create();
        $viewer = User::factory()->viewer()->create();

        $this->assertFalse($manager->isAdmin());
        $this->assertFalse($viewer->isAdmin());
    }

    #[Test]
    public function is_manager_returns_correctly(): void
    {
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($manager->isManager());
        $this->assertFalse($admin->isManager());
    }

    #[Test]
    public function is_viewer_returns_correctly(): void
    {
        $viewer = User::factory()->viewer()->create();
        $manager = User::factory()->manager()->create();

        $this->assertTrue($viewer->isViewer());
        $this->assertFalse($manager->isViewer());
    }

    #[Test]
    public function can_manage_sites_delegates_to_role(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $viewer = User::factory()->viewer()->create();

        $this->assertTrue($admin->canManageSites());
        $this->assertTrue($manager->canManageSites());
        $this->assertFalse($viewer->canManageSites());
    }

    #[Test]
    public function initials_attribute_works(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);

        $this->assertSame('JD', $user->initials);
    }

    #[Test]
    public function initials_works_with_single_name(): void
    {
        $user = User::factory()->create(['name' => 'Admin']);

        $this->assertSame('A', $user->initials);
    }

    #[Test]
    public function password_is_hashed(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->assertNotSame('password', $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $user->password));
    }

    #[Test]
    public function two_factor_secret_is_encrypted(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertTrue($user->two_factor_enabled);
    }
}
