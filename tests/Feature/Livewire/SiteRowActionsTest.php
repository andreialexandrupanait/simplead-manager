<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\SitesList;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every action the fleet site row offers must exist on the page rendering it.
 *
 * The landing page adopted the rich row without adopting all of its actions:
 * the ⋮ menu called confirmDelete(), which lived only on GlobalDashboard. The
 * menu item looked available, did nothing, and logged a
 * MethodNotFoundException on every click.
 */
class SiteRowActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_the_landing_page_answers_every_action_the_row_offers(): void
    {
        $row = file_get_contents(resource_path('views/components/dashboard/site-row.blade.php'));
        preg_match_all('/wire:click="(\w+)\(/', $row, $matches);

        $component = Livewire::test(SitesList::class)->instance();

        foreach (array_unique($matches[1]) as $method) {
            $this->assertTrue(
                method_exists($component, $method),
                "The site row calls {$method}() but SitesList does not define it.",
            );
        }
    }

    public function test_a_site_can_be_deleted_from_the_landing_page(): void
    {
        $site = Site::factory()->create(['name' => 'doomed.example']);

        Livewire::test(SitesList::class)
            ->call('confirmDelete', $site->id, $site->name)
            ->assertSet('deletingSiteName', 'doomed.example')
            ->call('deleteSite')
            ->assertDispatched('close-modal-delete-site');

        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }

    public function test_deleting_is_refused_for_a_site_the_user_cannot_touch(): void
    {
        $site = Site::factory()->create(['user_id' => User::factory()]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        Livewire::test(SitesList::class)
            ->call('confirmDelete', $site->id, $site->name)
            ->call('deleteSite')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }
}
