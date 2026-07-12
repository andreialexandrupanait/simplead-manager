<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Clients\ClientProfitability;
use App\Livewire\Clients\ClientsList;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-40: ClientsList must show clients a user is ASSIGNED to via the client_user
 * pivot in addition to clients they own through a site (admins see all). It
 * previously only matched owned sites and hid assigned clients.
 *
 * P2-39: ClientProfitability (sensitive financials) must be gated by ClientPolicy
 * so a user cannot open the profitability view for a client they may not access;
 * ClientsList's user-supplied sortBy must be whitelisted so it can never reach a
 * raw orderBy (SQL error / injection surface).
 */
class ClientAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name): Client
    {
        return Client::factory()->create([
            'name' => $name,
            'company' => null, // list renders company ?: name — force name
            'status' => 'active',
        ]);
    }

    public function test_user_sees_client_assigned_via_pivot(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $assigned = $this->client('Assigned Pivot Client');
        $user->assignedClients()->attach($assigned);

        Livewire::actingAs($user)
            ->test(ClientsList::class)
            ->assertSee('Assigned Pivot Client');
    }

    public function test_user_sees_client_owned_via_site(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $owned = $this->client('Owned Site Client');
        Site::factory()->create(['user_id' => $user->id, 'client_id' => $owned->id]);

        Livewire::actingAs($user)
            ->test(ClientsList::class)
            ->assertSee('Owned Site Client');
    }

    public function test_user_does_not_see_unrelated_client(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $this->client('Stranger Client');

        Livewire::actingAs($user)
            ->test(ClientsList::class)
            ->assertDontSee('Stranger Client');
    }

    public function test_admin_sees_all_clients(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->client('Admin Visible Client');

        Livewire::actingAs($admin)
            ->test(ClientsList::class)
            ->assertSee('Admin Visible Client');
    }

    public function test_invalid_sort_by_falls_back_and_does_not_error(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->client('Sortable Client');

        Livewire::actingAs($admin)
            ->test(ClientsList::class)
            ->set('sortBy', 'name"; DROP TABLE clients; --')
            ->assertOk()
            ->assertSee('Sortable Client');
    }

    public function test_profitability_forbidden_for_unrelated_user(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $client = $this->client('Private Financials Client');

        Livewire::actingAs($user)
            ->test(ClientProfitability::class, ['client' => $client])
            ->assertForbidden();
    }

    public function test_profitability_allowed_for_assigned_user(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $client = $this->client('Assigned Financials Client');
        $user->assignedClients()->attach($client);

        Livewire::actingAs($user)
            ->test(ClientProfitability::class, ['client' => $client])
            ->assertOk();
    }

    public function test_profitability_allowed_for_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = $this->client('Admin Financials Client');

        Livewire::actingAs($admin)
            ->test(ClientProfitability::class, ['client' => $client])
            ->assertOk();
    }
}
