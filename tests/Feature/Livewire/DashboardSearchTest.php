<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard\GlobalDashboard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();

        // Flush stats cache so computed values always reflect fresh data
        Cache::flush();
    }

    // ─── Search by URL (CQ-1 fix) ─────────────────────────────────────

    #[Test]
    public function dashboard_search_works_with_url(): void
    {
        // CQ-1 fix: DashboardService searches `url`, not `domain`
        $targetSite = Site::factory()->for($this->admin)->create([
            'url' => 'https://my-shop.example.com',
            'name' => 'My Shop',
        ]);
        Site::factory()->for($this->admin)->create([
            'url' => 'https://unrelated.example.com',
            'name' => 'Unrelated',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(GlobalDashboard::class)
            ->set('search', 'my-shop.example.com');

        $sites = $component->instance()->sites;

        $this->assertCount(1, $sites);
        $this->assertEquals($targetSite->id, $sites->first()->id);
    }

    // ─── Search by name ───────────────────────────────────────────────

    #[Test]
    public function dashboard_search_works_with_name(): void
    {
        $targetSite = Site::factory()->for($this->admin)->create(['name' => 'Acme Online Store']);
        Site::factory()->for($this->admin)->create(['name' => 'Another Site']);

        $component = Livewire::actingAs($this->admin)
            ->test(GlobalDashboard::class)
            ->set('search', 'Acme Online');

        $sites = $component->instance()->sites;

        $this->assertCount(1, $sites);
        $this->assertEquals($targetSite->id, $sites->first()->id);
    }

    // ─── Filter: healthy ──────────────────────────────────────────────

    #[Test]
    public function dashboard_filter_healthy_sites(): void
    {
        // DashboardService healthy: health_score >= 90 AND is_up = true
        $healthySite = Site::factory()->for($this->admin)->create([
            'health_score' => 95,
            'is_up' => true,
        ]);
        // This site has score >= 90 but is down — must be excluded
        Site::factory()->for($this->admin)->create([
            'health_score' => 92,
            'is_up' => false,
        ]);
        // This site is up but score is below threshold — must be excluded
        Site::factory()->for($this->admin)->create([
            'health_score' => 75,
            'is_up' => true,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(GlobalDashboard::class)
            ->set('filter', 'healthy');

        $sites = $component->instance()->sites;

        $this->assertCount(1, $sites);
        $this->assertEquals($healthySite->id, $sites->first()->id);
    }

    // ─── Filter: critical ─────────────────────────────────────────────

    #[Test]
    public function dashboard_filter_critical_sites(): void
    {
        // DashboardService critical: health_score < 70 OR is_up = false
        $downSite = Site::factory()->for($this->admin)->create([
            'health_score' => 85,
            'is_up' => false,
        ]);
        $lowScoreSite = Site::factory()->for($this->admin)->create([
            'health_score' => 45,
            'is_up' => true,
        ]);
        // Healthy site must not appear in critical results
        Site::factory()->for($this->admin)->create([
            'health_score' => 95,
            'is_up' => true,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(GlobalDashboard::class)
            ->set('filter', 'critical');

        $sites = $component->instance()->sites;

        $returnedIds = $sites->pluck('id')->sort()->values()->toArray();
        $expectedIds = collect([$downSite->id, $lowScoreSite->id])->sort()->values()->toArray();

        $this->assertEquals($expectedIds, $returnedIds);
    }
}
