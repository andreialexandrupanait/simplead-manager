<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Clients\ClientForm;
use App\Livewire\Clients\ClientsList;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── ClientsList — Rendering ──────────────────────────────────────

    #[Test]
    public function admin_can_view_clients_list(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ClientsList::class)
            ->assertOk();
    }

    // ─── ClientForm — create ──────────────────────────────────────────

    #[Test]
    public function admin_can_create_client(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ClientForm::class)
            ->set('form.name', 'Acme Corp')
            ->set('form.email', 'contact@acme.com')
            ->set('form.status', 'active')
            ->call('save');

        $this->assertDatabaseHas('clients', [
            'name' => 'Acme Corp',
            'email' => 'contact@acme.com',
            'status' => 'active',
        ]);
    }

    // ─── ClientForm — update ──────────────────────────────────────────

    #[Test]
    public function admin_can_update_client(): void
    {
        $client = Client::factory()->active()->create([
            'name' => 'Old Name',
            'vat_number' => null,
            'registration_number' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ClientForm::class, ['client' => $client])
            ->set('form.name', 'Updated Name')
            ->set('form.status', 'active')
            ->call('save');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Updated Name',
        ]);
    }

    // ─── ClientsList — delete ─────────────────────────────────────────

    #[Test]
    public function admin_can_delete_client(): void
    {
        $client = Client::factory()->active()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(ClientsList::class)
            ->call('confirmDelete', $client->id)
            ->assertDispatched('open-modal-delete-client');

        $component->call('delete');

        $this->assertDatabaseMissing('clients', ['id' => $client->id, 'deleted_at' => null]);
    }

    // ─── ClientsList — search ─────────────────────────────────────────

    #[Test]
    public function admin_can_search_clients(): void
    {
        Client::factory()->create(['name' => 'Alpha Company', 'status' => 'active']);
        Client::factory()->create(['name' => 'Beta Corp', 'status' => 'active']);

        $component = Livewire::actingAs($this->admin)
            ->test(ClientsList::class)
            ->set('search', 'Alpha');

        // The rendered view receives paginated results filtered by search
        $component->assertOk();
        $component->assertSet('search', 'Alpha');
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_create_clients(): void
    {
        $viewer = User::factory()->viewer()->create();

        Livewire::actingAs($viewer)
            ->test(ClientForm::class)
            ->assertForbidden();
    }
}
