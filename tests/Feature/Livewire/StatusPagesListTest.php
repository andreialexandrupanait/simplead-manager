<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\StatusPages\StatusPagesList;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatusPagesListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function createStatusPage(array $overrides = []): StatusPage
    {
        static $counter = 0;
        $counter++;

        return StatusPage::create(array_merge([
            'user_id' => $this->admin->id,
            'title' => "Status Page {$counter}",
            'slug' => "status-page-{$counter}",
            'primary_color' => '#7C3AED',
            'is_public' => true,
            'show_uptime_percentage' => true,
            'show_response_time' => false,
            'show_incident_history' => true,
            'incident_history_days' => 90,
            'auto_incidents' => false,
            'show_sla' => false,
        ], $overrides));
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_status_pages_list(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->assertOk();
    }

    #[Test]
    public function list_shows_existing_status_pages(): void
    {
        $this->createStatusPage(['title' => 'Acme Status']);
        $this->createStatusPage(['title' => 'Beta Status']);

        Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->assertOk();
    }

    #[Test]
    public function empty_list_renders_without_errors(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->assertOk();
    }

    // ─── confirmDelete() ──────────────────────────────────────────────

    #[Test]
    public function confirm_delete_sets_deleting_id_and_dispatches_modal_event(): void
    {
        $statusPage = $this->createStatusPage();

        $component = Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->call('confirmDelete', $statusPage->id);

        $this->assertEquals($statusPage->id, $component->get('deletingId'));
        $component->assertDispatched('open-modal-delete-status-page');
    }

    // ─── deleteStatusPage() ───────────────────────────────────────────

    #[Test]
    public function admin_can_delete_a_status_page(): void
    {
        $statusPage = $this->createStatusPage();

        Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->call('confirmDelete', $statusPage->id)
            ->call('deleteStatusPage');

        $this->assertDatabaseMissing('status_pages', ['id' => $statusPage->id]);
    }

    #[Test]
    public function delete_clears_deleting_id_after_deletion(): void
    {
        $statusPage = $this->createStatusPage();

        $component = Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->call('confirmDelete', $statusPage->id)
            ->call('deleteStatusPage');

        $this->assertNull($component->get('deletingId'));
    }

    #[Test]
    public function delete_without_confirmation_id_does_nothing(): void
    {
        $statusPage = $this->createStatusPage();

        // deletingId is null, no page should be deleted
        Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->call('deleteStatusPage');

        $this->assertDatabaseHas('status_pages', ['id' => $statusPage->id]);
    }

    #[Test]
    public function delete_dispatches_close_modal_event(): void
    {
        $statusPage = $this->createStatusPage();

        Livewire::actingAs($this->admin)
            ->test(StatusPagesList::class)
            ->call('confirmDelete', $statusPage->id)
            ->call('deleteStatusPage')
            ->assertDispatched('close-modal-delete-status-page');
    }
}
