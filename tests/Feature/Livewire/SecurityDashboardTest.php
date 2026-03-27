<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Security\SecurityDashboard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityDashboardTest extends TestCase
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
    public function admin_can_view_security_dashboard(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityDashboard::class)
            ->assertOk();
    }

    // ─── Data display ─────────────────────────────────────────────────

    #[Test]
    public function displays_security_monitors(): void
    {
        Site::factory()->for($this->admin)->create([
            'security_hardening_score' => 85,
        ]);

        Site::factory()->for($this->admin)->create([
            'security_hardening_score' => 30,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityDashboard::class)
            ->assertOk();

        // Both sites belong to the admin and should be included in the computed sites collection
        $this->assertCount(2, $component->instance()->sites);
    }

    #[Test]
    public function at_risk_count_reflects_low_scores(): void
    {
        Site::factory()->for($this->admin)->create(['security_hardening_score' => 30]);
        Site::factory()->for($this->admin)->create(['security_hardening_score' => 90]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityDashboard::class);

        $this->assertEquals(1, $component->instance()->atRiskSites);
    }

    #[Test]
    public function manager_only_sees_own_sites(): void
    {
        $manager = User::factory()->manager()->create();
        $otherAdmin = User::factory()->admin()->create();

        Site::factory()->for($manager)->create(['security_hardening_score' => 40]);
        Site::factory()->for($otherAdmin)->create(['security_hardening_score' => 40]);

        $component = Livewire::actingAs($manager)
            ->test(SecurityDashboard::class)
            ->assertOk();

        // Manager should see only their own site, not the admin's site
        $this->assertCount(1, $component->instance()->sites);
    }

    #[Test]
    public function search_filters_sites_by_name(): void
    {
        Site::factory()->for($this->admin)->create([
            'name' => 'Alpha Site',
            'url' => 'https://alpha.example.com',
        ]);

        Site::factory()->for($this->admin)->create([
            'name' => 'Beta Site',
            'url' => 'https://beta.example.com',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityDashboard::class)
            ->set('search', 'Alpha');

        $this->assertCount(1, $component->instance()->sites);
        $this->assertEquals('Alpha Site', $component->instance()->sites->first()->name);
    }
}
