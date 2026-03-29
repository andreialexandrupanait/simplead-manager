<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Tweaks\TweaksOverview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TweaksOverviewTest extends TestCase
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
    public function user_can_view_tweaks_overview_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TweaksOverview::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_when_no_tweak_settings_exist(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Livewire::actingAs($this->admin)
            ->test(TweaksOverview::class, ['site' => $site])
            ->assertOk();
    }

    // ─── Computed counts ──────────────────────────────────────────────

    #[Test]
    public function enabled_count_is_zero_with_no_settings(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksOverview::class, ['site' => $this->site]);

        $this->assertEquals(0, $component->instance()->enabledCount);
    }

    #[Test]
    public function applied_count_is_zero_with_no_settings(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksOverview::class, ['site' => $this->site]);

        $this->assertEquals(0, $component->instance()->appliedCount);
    }

    #[Test]
    public function failed_count_is_zero_with_no_settings(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksOverview::class, ['site' => $this->site]);

        $this->assertEquals(0, $component->instance()->failedCount);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_tweaks_overview(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(TweaksOverview::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
