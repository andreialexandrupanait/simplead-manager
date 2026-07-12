<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IncidentResponseStatus;
use App\Models\IncidentResponse;
use App\Models\NotificationTemplate;
use App\Models\Site;
use App\Services\SiteTodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-44: incident_response_* events must be registered as subscribable events,
 * and escalated/failed incidents must be visible to operators somewhere outside
 * the settings page — here, the per-site action-items feed.
 */
class IncidentResponseVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_response_events_are_registered(): void
    {
        $events = array_keys(NotificationTemplate::EVENTS);

        $this->assertContains('incident_response_resolved', $events);
        $this->assertContains('incident_response_escalated', $events);
        $this->assertContains('incident_response_failed', $events);
    }

    public function test_escalated_incident_appears_in_action_items_feed(): void
    {
        $site = Site::factory()->create(['is_up' => true, 'last_backup_at' => now()]);

        IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now(),
            'acknowledged_at' => null,
        ]);

        $todos = SiteTodoService::forSite($site);
        $categories = array_column($todos, 'category');

        $this->assertContains('incident', $categories);
    }

    public function test_failed_incident_appears_in_action_items_feed(): void
    {
        $site = Site::factory()->create(['is_up' => true, 'last_backup_at' => now()]);

        IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Failed,
            'acknowledged_at' => null,
        ]);

        $categories = array_column(SiteTodoService::forSite($site), 'category');

        $this->assertContains('incident', $categories);
    }

    public function test_acknowledged_incident_is_not_shown(): void
    {
        $site = Site::factory()->create(['is_up' => true, 'last_backup_at' => now()]);

        IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $categories = array_column(SiteTodoService::forSite($site), 'category');

        $this->assertNotContains('incident', $categories);
    }

    public function test_resolved_incident_does_not_appear(): void
    {
        $site = Site::factory()->create(['is_up' => true, 'last_backup_at' => now()]);

        IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Resolved,
            'resolved_at' => now(),
        ]);

        $categories = array_column(SiteTodoService::forSite($site), 'category');

        $this->assertNotContains('incident', $categories);
    }
}
