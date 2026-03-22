<?php

namespace Tests\Unit\Policies;

use App\Models\Site;
use App\Models\User;
use App\Policies\SitePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitePolicyTest extends TestCase
{
    use RefreshDatabase;

    private SitePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SitePolicy();
    }

    // ─── viewAny ────────────────────────────────────────────────────

    #[Test]
    public function any_user_can_view_any_sites(): void
    {
        $viewer = User::factory()->viewer()->create();
        $this->assertTrue($this->policy->viewAny($viewer));
    }

    // ─── view ───────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_any_site(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->for(User::factory())->create();

        $this->assertTrue($this->policy->view($admin, $site));
    }

    #[Test]
    public function owner_can_view_own_site(): void
    {
        $owner = User::factory()->manager()->create();
        $site = Site::factory()->for($owner)->create();

        $this->assertTrue($this->policy->view($owner, $site));
    }

    #[Test]
    public function non_owner_cannot_view_others_site(): void
    {
        $other = User::factory()->manager()->create();
        $site = Site::factory()->for(User::factory())->create();

        $this->assertFalse($this->policy->view($other, $site));
    }

    // ─── create ─────────────────────────────────────────────────────

    #[Test]
    public function admin_can_create_sites(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue($this->policy->create($admin));
    }

    #[Test]
    public function manager_can_create_sites(): void
    {
        $manager = User::factory()->manager()->create();
        $this->assertTrue($this->policy->create($manager));
    }

    #[Test]
    public function viewer_cannot_create_sites(): void
    {
        $viewer = User::factory()->viewer()->create();
        $this->assertFalse($this->policy->create($viewer));
    }

    // ─── update ─────────────────────────────────────────────────────

    #[Test]
    public function admin_can_update_any_site(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->for(User::factory())->create();

        $this->assertTrue($this->policy->update($admin, $site));
    }

    #[Test]
    public function manager_can_update_own_site(): void
    {
        $manager = User::factory()->manager()->create();
        $site = Site::factory()->for($manager)->create();

        $this->assertTrue($this->policy->update($manager, $site));
    }

    #[Test]
    public function manager_cannot_update_others_site(): void
    {
        $manager = User::factory()->manager()->create();
        $site = Site::factory()->for(User::factory())->create();

        $this->assertFalse($this->policy->update($manager, $site));
    }

    #[Test]
    public function viewer_cannot_update_any_site(): void
    {
        $viewer = User::factory()->viewer()->create();
        $site = Site::factory()->for($viewer)->create();

        $this->assertFalse($this->policy->update($viewer, $site));
    }

    // ─── delete ─────────────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_sites(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->for(User::factory())->create();

        $this->assertTrue($this->policy->delete($admin, $site));
    }

    #[Test]
    public function manager_cannot_delete_sites(): void
    {
        $manager = User::factory()->manager()->create();
        $site = Site::factory()->for($manager)->create();

        $this->assertFalse($this->policy->delete($manager, $site));
    }

    #[Test]
    public function viewer_cannot_delete_sites(): void
    {
        $viewer = User::factory()->viewer()->create();
        $site = Site::factory()->for($viewer)->create();

        $this->assertFalse($this->policy->delete($viewer, $site));
    }
}
