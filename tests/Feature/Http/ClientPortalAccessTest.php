<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Client;
use App\Models\Report;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-06: an archived/inactive client must not keep a live public portal — the
 * portal is only reachable when the feature is enabled AND the client is active.
 *
 * P2-07: the portal report view must only render a fully generated report
 * (status COMPLETED with a data snapshot). Pending/generating/failed reports
 * would render a broken/empty page and must be excluded (404).
 */
class ClientPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeClient(string $status): Client
    {
        return Client::factory()->create([
            'status' => $status,
            'portal_enabled' => true,
            'portal_token' => str_repeat('a', 64),
        ]);
    }

    public function test_active_client_portal_is_accessible(): void
    {
        $client = $this->makeClient('active');

        $this->get("/portal/{$client->portal_token}")->assertOk();
    }

    public function test_archived_client_portal_returns_404(): void
    {
        $client = $this->makeClient('archived');

        $this->get("/portal/{$client->portal_token}")->assertNotFound();
    }

    public function test_inactive_client_portal_returns_404(): void
    {
        $client = $this->makeClient('inactive');

        $this->get("/portal/{$client->portal_token}")->assertNotFound();
    }

    public function test_disabled_portal_returns_404_even_when_active(): void
    {
        $client = Client::factory()->create([
            'status' => 'active',
            'portal_enabled' => false,
            'portal_token' => str_repeat('b', 64),
        ]);

        $this->get("/portal/{$client->portal_token}")->assertNotFound();
    }

    public function test_completed_report_with_snapshot_renders(): void
    {
        $client = $this->makeClient('active');
        $site = Site::factory()->create(['client_id' => $client->id]);
        $report = Report::factory()->completed()->create([
            'site_id' => $site->id,
            'data_snapshot' => ['_meta' => ['sections' => []]],
        ]);

        $this->get("/portal/{$client->portal_token}/reports/{$report->id}")->assertOk();
    }

    public function test_generating_report_is_not_rendered(): void
    {
        $client = $this->makeClient('active');
        $site = Site::factory()->create(['client_id' => $client->id]);
        $report = Report::factory()->generating()->create([
            'site_id' => $site->id,
            'data_snapshot' => null,
        ]);

        $this->get("/portal/{$client->portal_token}/reports/{$report->id}")->assertNotFound();
    }

    public function test_failed_report_is_not_rendered(): void
    {
        $client = $this->makeClient('active');
        $site = Site::factory()->create(['client_id' => $client->id]);
        $report = Report::factory()->failed()->create(['site_id' => $site->id]);

        $this->get("/portal/{$client->portal_token}/reports/{$report->id}")->assertNotFound();
    }

    public function test_completed_report_without_snapshot_is_not_rendered(): void
    {
        $client = $this->makeClient('active');
        $site = Site::factory()->create(['client_id' => $client->id]);
        $report = Report::factory()->completed()->create([
            'site_id' => $site->id,
            'data_snapshot' => null,
        ]);

        $this->get("/portal/{$client->portal_token}/reports/{$report->id}")->assertNotFound();
    }

    public function test_report_from_archived_client_is_not_accessible(): void
    {
        $client = $this->makeClient('archived');
        $site = Site::factory()->create(['client_id' => $client->id]);
        $report = Report::factory()->completed()->create([
            'site_id' => $site->id,
            'data_snapshot' => ['_meta' => ['sections' => []]],
        ]);

        $this->get("/portal/{$client->portal_token}/reports/{$report->id}")->assertNotFound();
    }
}
