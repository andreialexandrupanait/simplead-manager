<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\StatusPages\StatusPageEdit;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatusPageEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StatusPage $statusPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->statusPage = StatusPage::create([
            'user_id' => $this->admin->id,
            'title' => 'My Service Status',
            'slug' => 'my-service-status',
            'primary_color' => '#7C3AED',
            'is_public' => true,
            'show_uptime_percentage' => true,
            'show_response_time' => false,
            'show_incident_history' => true,
            'incident_history_days' => 90,
            'auto_incidents' => true,
            'show_sla' => false,
        ]);
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_status_page_edit_form(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->assertOk()
            ->assertSet('title', 'My Service Status')
            ->assertSet('slug', 'my-service-status');
    }

    #[Test]
    public function user_can_view_create_form_without_status_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class)
            ->assertOk()
            ->assertSet('title', '')
            ->assertSet('slug', '');
    }

    // ─── save() — create ──────────────────────────────────────────────

    #[Test]
    public function user_can_create_status_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class)
            ->set('title', 'New Status Page')
            ->set('slug', 'new-status-page')
            ->set('description', 'A description')
            ->set('primaryColor', '#3b82f6')
            ->set('incidentHistoryDays', 60)
            ->call('save');

        $this->assertDatabaseHas('status_pages', [
            'title' => 'New Status Page',
            'slug' => 'new-status-page',
            'user_id' => $this->admin->id,
        ]);
    }

    // ─── save() — update ──────────────────────────────────────────────

    #[Test]
    public function user_can_update_status_page_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->set('title', 'Updated Title')
            ->set('description', 'Updated description')
            ->set('showResponseTime', true)
            ->set('incidentHistoryDays', 30)
            ->call('save');

        $this->assertDatabaseHas('status_pages', [
            'id' => $this->statusPage->id,
            'title' => 'Updated Title',
            'show_response_time' => true,
            'incident_history_days' => 30,
        ]);
    }

    // ─── createIncident() ─────────────────────────────────────────────

    #[Test]
    public function user_can_create_incident_on_status_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->set('incidentTitle', 'API Outage')
            ->set('incidentDescription', 'Our API is experiencing issues.')
            ->set('incidentSeverity', 'major')
            ->call('createIncident');

        $this->assertDatabaseHas('status_page_incidents', [
            'status_page_id' => $this->statusPage->id,
            'title' => 'API Outage',
            'severity' => 'major',
            'status' => 'investigating',
        ]);
    }

    #[Test]
    public function creating_incident_also_creates_initial_update(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->set('incidentTitle', 'Database Slowdown')
            ->set('incidentDescription', 'Slow queries detected.')
            ->set('incidentSeverity', 'minor')
            ->call('createIncident');

        $incident = StatusPageIncident::where('status_page_id', $this->statusPage->id)->first();
        $this->assertNotNull($incident);
        $this->assertDatabaseHas('status_page_incident_updates', [
            'status_page_incident_id' => $incident->id,
            'status' => 'investigating',
        ]);
    }

    // ─── updateIncidentStatus() ───────────────────────────────────────

    #[Test]
    public function user_can_update_incident_status(): void
    {
        $incident = $this->statusPage->incidents()->create([
            'title' => 'Ongoing Issue',
            'status' => 'investigating',
            'severity' => 'minor',
            'started_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->call('updateIncidentStatus', $incident->id, 'identified');

        $this->assertDatabaseHas('status_page_incidents', [
            'id' => $incident->id,
            'status' => 'identified',
        ]);
    }

    // ─── resolveIncident() ────────────────────────────────────────────

    #[Test]
    public function user_can_resolve_incident(): void
    {
        $incident = $this->statusPage->incidents()->create([
            'title' => 'Active Incident',
            'status' => 'monitoring',
            'severity' => 'minor',
            'started_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->call('resolveIncident', $incident->id);

        $this->assertDatabaseHas('status_page_incidents', [
            'id' => $incident->id,
            'status' => 'resolved',
        ]);

        $this->assertNotNull(
            StatusPageIncident::find($incident->id)->resolved_at
        );
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function save_requires_title(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class)
            ->set('title', '')
            ->set('slug', 'some-slug')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);
    }

    #[Test]
    public function save_requires_unique_slug(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class)
            ->set('title', 'Duplicate')
            ->set('slug', 'my-service-status') // already exists
            ->call('save')
            ->assertHasErrors(['slug']);
    }

    #[Test]
    public function create_incident_requires_title(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->set('incidentTitle', '')
            ->set('incidentSeverity', 'minor')
            ->call('createIncident')
            ->assertHasErrors(['incidentTitle' => 'required']);
    }

    #[Test]
    public function create_incident_requires_valid_severity(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusPageEdit::class, ['statusPage' => $this->statusPage])
            ->set('incidentTitle', 'Valid Title')
            ->set('incidentSeverity', 'catastrophic')
            ->call('createIncident')
            ->assertHasErrors(['incidentSeverity']);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_edit_another_users_status_page(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherPage = StatusPage::create([
            'user_id' => $otherAdmin->id,
            'title' => 'Other Page',
            'slug' => 'other-page',
            'primary_color' => '#000000',
            'is_public' => true,
            'show_uptime_percentage' => true,
            'show_response_time' => false,
            'show_incident_history' => true,
            'incident_history_days' => 90,
            'auto_incidents' => true,
            'show_sla' => false,
        ]);

        Livewire::actingAs($viewer)
            ->test(StatusPageEdit::class, ['statusPage' => $otherPage])
            ->assertForbidden();
    }
}
