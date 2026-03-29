<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Security\SecurityOverview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityOverviewTest extends TestCase
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
    public function user_can_view_security_overview_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityOverview::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_no_security_settings(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Livewire::actingAs($this->admin)
            ->test(SecurityOverview::class, ['site' => $site])
            ->assertOk();
    }

    // ─── activateModule() ─────────────────────────────────────────────

    #[Test]
    public function user_can_activate_security_module(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityOverview::class, ['site' => $this->site]);

        // Calling activateModule should not throw
        $component->call('activateModule');
        $component->assertOk();
    }

    // ─── securityScore computed ───────────────────────────────────────

    #[Test]
    public function security_score_reflects_site_attribute(): void
    {
        $this->site->update(['security_hardening_score' => 75]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityOverview::class, ['site' => $this->site]);

        $this->assertEquals(75, $component->instance()->securityScore);
    }

    #[Test]
    public function security_score_is_null_when_not_set(): void
    {
        $this->site->update(['security_hardening_score' => null]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityOverview::class, ['site' => $this->site]);

        $this->assertNull($component->instance()->securityScore);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_security_overview(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityOverview::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
