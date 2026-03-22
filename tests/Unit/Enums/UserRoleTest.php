<?php

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    #[Test]
    public function admin_can_manage_sites(): void
    {
        $this->assertTrue(UserRole::Admin->canManageSites());
    }

    #[Test]
    public function manager_can_manage_sites(): void
    {
        $this->assertTrue(UserRole::Manager->canManageSites());
    }

    #[Test]
    public function viewer_cannot_manage_sites(): void
    {
        $this->assertFalse(UserRole::Viewer->canManageSites());
    }

    #[Test]
    public function only_admin_can_access_settings(): void
    {
        $this->assertTrue(UserRole::Admin->canAccessSettings());
        $this->assertFalse(UserRole::Manager->canAccessSettings());
        $this->assertFalse(UserRole::Viewer->canAccessSettings());
    }

    #[Test]
    public function only_admin_can_delete_resources(): void
    {
        $this->assertTrue(UserRole::Admin->canDeleteResources());
        $this->assertFalse(UserRole::Manager->canDeleteResources());
        $this->assertFalse(UserRole::Viewer->canDeleteResources());
    }

    #[Test]
    public function it_has_labels(): void
    {
        $this->assertSame('Admin', UserRole::Admin->label());
        $this->assertSame('Manager', UserRole::Manager->label());
        $this->assertSame('Viewer', UserRole::Viewer->label());
    }
}
