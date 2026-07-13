<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Clients\ClientDetail;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3-25: (a) enabling/disabling a portal and regenerating its token are
 * security-relevant and must be written to the activity log; (b) the portal
 * view must not run health-score logic itself — the controller computes it.
 */
class ClientPortalActivityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_enabling_portal_writes_an_activity_log_entry(): void
    {
        $client = Client::factory()->create(['status' => 'active', 'portal_enabled' => false, 'portal_token' => null]);

        Livewire::actingAs($this->admin())
            ->test(ClientDetail::class, ['client' => $client])
            ->call('togglePortal');

        $this->assertTrue($client->fresh()->portal_enabled);
        $this->assertDatabaseHas('activity_logs', [
            'type' => 'portal',
            'title' => "Client portal enabled for {$client->name}",
        ]);
    }

    public function test_disabling_portal_writes_an_activity_log_entry(): void
    {
        $client = Client::factory()->create(['status' => 'active', 'portal_enabled' => true, 'portal_token' => str_repeat('a', 64)]);

        Livewire::actingAs($this->admin())
            ->test(ClientDetail::class, ['client' => $client])
            ->call('togglePortal');

        $this->assertFalse($client->fresh()->portal_enabled);
        $this->assertDatabaseHas('activity_logs', [
            'type' => 'portal',
            'title' => "Client portal disabled for {$client->name}",
        ]);
    }

    public function test_regenerating_the_token_writes_an_activity_log_entry(): void
    {
        $client = Client::factory()->create(['status' => 'active', 'portal_enabled' => true, 'portal_token' => str_repeat('a', 64)]);

        Livewire::actingAs($this->admin())
            ->test(ClientDetail::class, ['client' => $client])
            ->call('regeneratePortalToken');

        $this->assertNotSame(str_repeat('a', 64), $client->fresh()->portal_token);
        $this->assertDatabaseHas('activity_logs', [
            'type' => 'portal',
            'title' => "Client portal token regenerated for {$client->name}",
        ]);
    }

    public function test_portal_health_is_computed_by_the_controller_and_passed_to_the_view(): void
    {
        $client = Client::factory()->create(['status' => 'active', 'portal_enabled' => true, 'portal_token' => str_repeat('b', 64)]);
        $site = Site::factory()->create(['client_id' => $client->id]);

        $response = $this->get(route('client-portal.show', $client->portal_token));

        $response->assertOk();
        $response->assertViewHas('healthScores', fn ($scores) => is_array($scores) && array_key_exists($site->id, $scores));
    }
}
