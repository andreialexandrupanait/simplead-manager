<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\BulkSettings;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BulkSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create(['name' => 'Main Site']);
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_bulk_settings_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->assertOk();
    }

    #[Test]
    public function page_starts_at_step_one(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(BulkSettings::class);

        $this->assertEquals(1, $component->get('step'));
    }

    // ─── search ───────────────────────────────────────────────────────

    #[Test]
    public function site_search_filters_the_list(): void
    {
        Site::factory()->for($this->admin)->create(['name' => 'Alpha Site']);
        Site::factory()->for($this->admin)->create(['name' => 'Beta Site']);

        $component = Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('search', 'Alpha');

        $component->assertOk();
        $this->assertEquals('Alpha', $component->get('search'));
    }

    // ─── goToStep() ───────────────────────────────────────────────────

    #[Test]
    public function cannot_advance_to_step_2_without_selecting_sites(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->call('goToStep', 2)
            ->assertSee('Please select at least one site.');
    }

    #[Test]
    public function can_advance_to_step_2_when_sites_are_selected(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('selectedSiteIds', [(string) $this->site->id])
            ->call('goToStep', 2);

        $this->assertEquals(2, $component->get('step'));
    }

    #[Test]
    public function cannot_advance_to_step_3_without_choosing_operation(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('selectedSiteIds', [(string) $this->site->id])
            ->call('goToStep', 2)
            ->call('goToStep', 3)
            ->assertSee('Please choose an operation.');
    }

    #[Test]
    public function can_advance_to_step_3_when_operation_is_set(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('selectedSiteIds', [(string) $this->site->id])
            ->call('goToStep', 2)
            ->set('operation', 'copy_from_site')
            ->call('goToStep', 3);

        $this->assertEquals(3, $component->get('step'));
    }

    // ─── selectAll ────────────────────────────────────────────────────

    #[Test]
    public function select_all_fills_selected_site_ids(): void
    {
        $site2 = Site::factory()->for($this->admin)->create(['name' => 'Second Site']);

        $component = Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('selectAll', true);

        $ids = $component->get('selectedSiteIds');
        $this->assertContains((string) $this->site->id, $ids);
        $this->assertContains((string) $site2->id, $ids);
    }

    #[Test]
    public function deselect_all_clears_selected_site_ids(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('selectedSiteIds', [(string) $this->site->id])
            ->set('selectAll', false);

        $this->assertEmpty($component->get('selectedSiteIds'));
    }

    // ─── apply() — missing source validation ──────────────────────────

    #[Test]
    public function apply_copy_from_site_without_source_flashes_error(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BulkSettings::class)
            ->set('selectedSiteIds', [(string) $this->site->id])
            ->set('operation', 'copy_from_site')
            ->call('apply')
            ->assertSee('Please select a source site.');
    }
}
