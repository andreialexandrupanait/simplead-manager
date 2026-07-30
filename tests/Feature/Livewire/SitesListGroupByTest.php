<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\SitesList;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §4.4 — group the site list by client.
 */
class SitesListGroupByTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_groups_the_list_by_client_and_shows_client_headers(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $acme = Client::factory()->create(['name' => 'Acme']);
        Site::factory()->create(['name' => 'acme-one', 'client_id' => $acme->id, 'is_up' => true, 'is_connected' => true]);

        Livewire::test(SitesList::class)
            ->set('viewMode', 'list')
            ->assertSet('groupBy', 'none')
            ->call('toggleGroupByClient')
            ->assertSet('groupBy', 'client')
            ->assertSee('Acme')
            ->assertSee('acme-one')
            ->call('toggleGroupByClient')
            ->assertSet('groupBy', 'none');
    }
}
