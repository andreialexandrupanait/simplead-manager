<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\SitesList;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faza 3 — the sites list must render in both grid and dense list view.
 */
class SitesListViewModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::actingAs(User::factory()->admin()->create());
    }

    public function test_grid_view_renders(): void
    {
        $site = Site::factory()->create(['name' => 'Grid Site']);

        Livewire::test(SitesList::class)
            ->assertOk()
            ->assertSet('viewMode', 'grid')
            ->assertSee('Grid Site');
    }

    public function test_list_view_renders(): void
    {
        $site = Site::factory()->create(['name' => 'List Site']);

        Livewire::test(SitesList::class)
            ->call('setViewMode', 'list')
            ->assertOk()
            ->assertSet('viewMode', 'list')
            ->assertSee('List Site');
    }

    public function test_set_view_mode_rejects_invalid_value(): void
    {
        Livewire::test(SitesList::class)
            ->call('setViewMode', 'bogus')
            ->assertSet('viewMode', 'grid');
    }

    public function test_clear_selection_empties_selected_sites(): void
    {
        Livewire::test(SitesList::class)
            ->set('selectedSites', [1, 2, 3])
            ->call('clearSelection')
            ->assertSet('selectedSites', []);
    }
}
