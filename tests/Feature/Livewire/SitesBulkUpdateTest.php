<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\QueueSiteSafeUpdatesJob;
use App\Jobs\RunOperationJob;
use App\Jobs\RunSafeUpdate;
use App\Livewire\Sites\SitesList;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use App\Operations\Operations\QueueSafeUpdatesOperation;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §2 (bulk-first) / §4.4 — fleet bulk update fans out one safe-update job
 * per eligible site and skips the rest (never an unprotected inline update).
 */
class SitesBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_bulk_update_fans_out_a_job_only_for_eligible_sites(): void
    {
        Queue::fake();

        $eligible = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => true]);
        $disconnected = Site::factory()->create(['is_connected' => false, 'safe_updates_enabled' => true]);
        $unsafe = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => false]);

        Livewire::test(SitesList::class)
            ->set('selectedSites', [$eligible->id, $disconnected->id, $unsafe->id])
            ->call('bulkUpdate');

        // The fan-out now goes through the operations engine (SPEC §6.2): one
        // RunOperationJob for the single eligible site, none for the other two.
        Queue::assertPushed(RunOperationJob::class, 1);
        Queue::assertPushed(fn (RunOperationJob $j): bool => $j->siteId === $eligible->id
            && $j->operationClass === QueueSafeUpdatesOperation::class);
    }

    public function test_the_fanout_job_queues_one_safe_update_per_pending_item(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => true]);
        SitePlugin::factory()->for($site)->create(['has_update' => true, 'name' => 'Needs Update']);
        SitePlugin::factory()->for($site)->create(['has_update' => false, 'name' => 'Current']);

        (new QueueSiteSafeUpdatesJob($site, null))->handle(app(SafeUpdateService::class));

        // Exactly one RunSafeUpdate (the plugin with has_update), and one SafeUpdate row.
        Queue::assertPushed(RunSafeUpdate::class, 1);
        $this->assertDatabaseCount('safe_updates', 1);
    }

    public function test_the_fanout_job_is_a_noop_on_ineligible_sites(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => false, 'safe_updates_enabled' => true]);
        SitePlugin::factory()->for($site)->create(['has_update' => true]);

        (new QueueSiteSafeUpdatesJob($site, null))->handle(app(SafeUpdateService::class));

        Queue::assertNotPushed(RunSafeUpdate::class);
        $this->assertDatabaseCount('safe_updates', 0);
    }
}
