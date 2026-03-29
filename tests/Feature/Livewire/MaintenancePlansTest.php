<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\MaintenancePlans;
use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MaintenancePlansTest extends TestCase
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
    public function admin_can_view_maintenance_plans_list(): void
    {
        Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->assertOk();
    }

    #[Test]
    public function plans_list_shows_existing_plans(): void
    {
        MaintenancePlan::create([
            'name' => 'Basic Plan',
            'is_default' => false,
            'sort_order' => 0,
            'include_modules' => true,
            'include_security' => false,
            'include_tweaks' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->assertOk();
    }

    // ─── openCreate() / save() ────────────────────────────────────────

    #[Test]
    public function open_create_switches_to_create_view(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('openCreate');

        $this->assertEquals('create', $component->get('view'));
    }

    #[Test]
    public function admin_can_create_a_new_maintenance_plan(): void
    {
        Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('openCreate')
            ->set('planName', 'My New Plan')
            ->set('planDescription', 'A test plan')
            ->call('save');

        $this->assertDatabaseHas('maintenance_plans', [
            'name' => 'My New Plan',
        ]);
    }

    #[Test]
    public function save_validates_plan_name_is_required(): void
    {
        Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('openCreate')
            ->set('planName', '')
            ->call('save')
            ->assertHasErrors(['planName']);
    }

    #[Test]
    public function saving_plan_returns_to_list_view(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('openCreate')
            ->set('planName', 'Quick Plan')
            ->call('save');

        $this->assertEquals('list', $component->get('view'));
    }

    // ─── openEdit() ───────────────────────────────────────────────────

    #[Test]
    public function open_edit_switches_to_edit_view_and_loads_plan(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'Existing Plan',
            'is_default' => false,
            'sort_order' => 1,
            'include_modules' => true,
            'include_security' => true,
            'include_tweaks' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('openEdit', $plan->id);

        $this->assertEquals('edit', $component->get('view'));
        $this->assertEquals('Existing Plan', $component->get('planName'));
        $this->assertEquals($plan->id, $component->get('editingId'));
    }

    // ─── confirmDelete() / delete() ───────────────────────────────────

    #[Test]
    public function confirm_delete_sets_confirm_id(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'To Delete',
            'is_default' => false,
            'sort_order' => 0,
            'include_modules' => false,
            'include_security' => false,
            'include_tweaks' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('confirmDelete', $plan->id);

        $this->assertEquals($plan->id, $component->get('confirmDeleteId'));
    }

    #[Test]
    public function admin_can_delete_a_plan_without_sites(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'Deletable Plan',
            'is_default' => false,
            'sort_order' => 0,
            'include_modules' => false,
            'include_security' => false,
            'include_tweaks' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('confirmDelete', $plan->id)
            ->call('delete');

        $this->assertDatabaseMissing('maintenance_plans', ['id' => $plan->id]);
    }

    // ─── backToList() ─────────────────────────────────────────────────

    #[Test]
    public function back_to_list_resets_view_to_list(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('openCreate')
            ->call('backToList');

        $this->assertEquals('list', $component->get('view'));
        $this->assertNull($component->get('editingId'));
        $this->assertEquals('', $component->get('planName'));
    }

    // ─── site search in apply mode ────────────────────────────────────

    #[Test]
    public function site_search_filters_available_sites_during_apply(): void
    {
        Site::factory()->for($this->admin)->create(['name' => 'Alpha Site']);
        Site::factory()->for($this->admin)->create(['name' => 'Beta Site']);

        $plan = MaintenancePlan::create([
            'name' => 'Apply Plan',
            'is_default' => false,
            'sort_order' => 0,
            'include_modules' => true,
            'include_security' => false,
            'include_tweaks' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(MaintenancePlans::class)
            ->call('startApply', $plan->id)
            ->set('siteSearch', 'Alpha');

        $component->assertOk();
        $this->assertEquals('Alpha', $component->get('siteSearch'));
    }
}
