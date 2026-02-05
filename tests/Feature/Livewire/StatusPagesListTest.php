<?php

namespace Tests\Feature\Livewire;

use App\Livewire\StatusPages\StatusPagesList;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusPagesListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_component_renders(): void
    {
        Livewire::actingAs($this->user)
            ->test(StatusPagesList::class)
            ->assertOk()
            ->assertSee('Status Pages');
    }

    public function test_displays_existing_status_pages(): void
    {
        $page = StatusPage::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Acme Corp Status',
            'slug' => 'acme-corp',
        ]);

        Livewire::actingAs($this->user)
            ->test(StatusPagesList::class)
            ->assertSee('Acme Corp Status');
    }

    public function test_can_delete_status_page(): void
    {
        $page = StatusPage::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Deletable Page',
        ]);

        Livewire::actingAs($this->user)
            ->test(StatusPagesList::class)
            ->assertSee('Deletable Page')
            ->call('confirmDelete', $page->id)
            ->assertSet('deletingId', $page->id)
            ->call('deleteStatusPage');

        $this->assertDatabaseMissing('status_pages', [
            'id' => $page->id,
        ]);
    }
}
