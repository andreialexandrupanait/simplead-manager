<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Models\SiteTask;
use App\Services\SiteTaskSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SPEC §11 — "app.simplead.ro | sarcini per site, doar citire | API
 * unidirecțional".
 *
 * Two things matter here beyond "it fetches": that sites link to their upstream
 * project without anyone maintaining a mapping table by hand, and that the
 * financial half of the upstream payload never lands in this database — SPEC §1
 * puts money in SAD Hub.
 */
class SiteTaskSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://app.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.simplead_app.base_url', self::BASE);
        Config::set('services.simplead_app.token', 'pat_test_token');
    }

    private function fakeUpstream(array $projects, array $tasksByProject): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($projects, $tasksByProject) {
            $url = $request->url();

            if (str_contains($url, '/api/projects')) {
                return Http::response($projects, 200);
            }

            foreach ($tasksByProject as $projectId => $tasks) {
                if (str_contains($url, "/api/lists/{$projectId}/tasks")) {
                    return Http::response(['tasks' => $tasks], 200);
                }
            }

            return Http::response(['tasks' => []], 200);
        });
    }

    private function upstreamTask(array $overrides = []): array
    {
        // The real shape, financial fields included — they must be dropped.
        return array_merge([
            'id' => 'task-1',
            'name' => 'Modificari homepage',
            'status' => 'completed',
            'taskType' => 'task',
            'statusEnteredAt' => '2026-07-15T10:00:00.000Z',
            'dueDate' => null,
            'startDate' => null,
            'updatedAt' => '2026-07-15T10:00:00.000Z',
            'assignee' => ['id' => 'u1', 'name' => 'Andrei'],
            'ownerRate' => '25',
            'partnerRate' => '35',
            'costTotal' => '2',
            'partnerTotal' => '4.55',
            'financialStatus' => 'facturat',
            'partnerInvoiceNumber' => 'INV-100',
        ], $overrides);
    }

    public function test_a_site_links_to_its_project_by_domain_name(): void
    {
        $site = Site::factory()->create(['url' => 'https://asic.ro', 'name' => 'asic.ro']);

        $this->fakeUpstream(
            [['id' => 'proj-asic', 'name' => 'asic.ro', 'client' => 'Activi']],
            ['proj-asic' => [$this->upstreamTask()]],
        );

        app(SiteTaskSyncService::class)->syncAll();

        $this->assertSame('proj-asic', $site->fresh()->external_project_id);
        $this->assertDatabaseHas('site_tasks', ['site_id' => $site->id, 'external_id' => 'task-1']);
    }

    public function test_a_human_project_name_still_matches_its_domain(): void
    {
        // "M1 Beauty" upstream, m1-beauty.at here.
        $site = Site::factory()->create(['url' => 'https://m1-beauty.at', 'name' => 'm1-beauty.at']);

        $this->fakeUpstream(
            [['id' => 'proj-m1', 'name' => 'M1 Beauty', 'client' => 'Mentenanta']],
            ['proj-m1' => [$this->upstreamTask()]],
        );

        app(SiteTaskSyncService::class)->syncAll();

        $this->assertSame('proj-m1', $site->fresh()->external_project_id);
    }

    public function test_no_financial_field_is_ever_stored(): void
    {
        $site = Site::factory()->create(['url' => 'https://asic.ro', 'name' => 'asic.ro']);

        $this->fakeUpstream(
            [['id' => 'proj-asic', 'name' => 'asic.ro']],
            ['proj-asic' => [$this->upstreamTask()]],
        );

        app(SiteTaskSyncService::class)->syncAll();

        $stored = SiteTask::firstOrFail()->getAttributes();

        foreach (['ownerRate', 'partnerRate', 'costTotal', 'partnerTotal', 'financialStatus', 'partnerInvoiceNumber'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $stored, "money belongs in SAD Hub, not here ({$forbidden})");
        }

        $this->assertSame('Andrei', $stored['assignee_name']);
        $this->assertSame('completed', $stored['status']);
    }

    public function test_a_site_with_no_matching_project_is_left_alone(): void
    {
        $site = Site::factory()->create(['url' => 'https://feaagalati.ro', 'name' => 'feaagalati.ro']);

        $this->fakeUpstream([['id' => 'proj-other', 'name' => 'asic.ro']], []);

        app(SiteTaskSyncService::class)->syncAll();

        $this->assertNull($site->fresh()->external_project_id);
        $this->assertDatabaseCount('site_tasks', 0);
    }

    public function test_a_manual_link_is_never_overwritten(): void
    {
        $site = Site::factory()->create([
            'url' => 'https://asic.ro',
            'name' => 'asic.ro',
            'external_project_id' => 'pinned-by-hand',
        ]);

        $this->fakeUpstream(
            [['id' => 'proj-asic', 'name' => 'asic.ro']],
            ['pinned-by-hand' => [$this->upstreamTask()]],
        );

        app(SiteTaskSyncService::class)->syncAll();

        $this->assertSame('pinned-by-hand', $site->fresh()->external_project_id);
    }

    public function test_a_task_removed_upstream_disappears_here(): void
    {
        $site = Site::factory()->create(['url' => 'https://asic.ro', 'name' => 'asic.ro', 'external_project_id' => 'p']);
        SiteTask::create(['site_id' => $site->id, 'external_id' => 'gone', 'name' => 'Old', 'status' => 'completed']);

        $this->fakeUpstream([], ['p' => [$this->upstreamTask(['id' => 'kept'])]]);

        app(SiteTaskSyncService::class)->syncSite($site);

        $this->assertDatabaseMissing('site_tasks', ['external_id' => 'gone']);
        $this->assertDatabaseHas('site_tasks', ['external_id' => 'kept']);
    }

    public function test_an_empty_or_failed_response_does_not_wipe_stored_tasks(): void
    {
        $site = Site::factory()->create(['url' => 'https://asic.ro', 'name' => 'asic.ro', 'external_project_id' => 'p']);
        SiteTask::create(['site_id' => $site->id, 'external_id' => 'keep-me', 'name' => 'Work', 'status' => 'completed']);

        Http::fake(['*' => Http::response('upstream down', 500)]);

        app(SiteTaskSyncService::class)->syncSite($site);

        $this->assertDatabaseHas('site_tasks', ['external_id' => 'keep-me']);
    }

    public function test_nothing_happens_without_a_token(): void
    {
        Config::set('services.simplead_app.token', null);
        Http::preventStrayRequests();

        $result = app(SiteTaskSyncService::class)->syncAll();

        $this->assertSame(['sites' => 0, 'tasks' => 0, 'linked' => 0], $result);
    }
}
