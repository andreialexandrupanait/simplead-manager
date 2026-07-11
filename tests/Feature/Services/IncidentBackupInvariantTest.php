<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IncidentResponseStatus;
use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\DatabaseCleanupService;
use App\Services\IncidentResponse\IncidentActionExecutor;
use App\Services\PluginManagerService;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * P0-20 (audit IR-01): the "backup before destructive action" invariant used to
 * pass silently — createBackup() stamped backup_created=true even when the site
 * had no backup config or the CreateBackup job returned without producing one.
 * These tests pin the corrected behaviour: a destructive action proceeds ONLY
 * when a completed + verified Backup row actually exists; otherwise it is refused.
 */
class IncidentBackupInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private IncidentResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // swallow FetchSiteFavicon dispatched on Site creation
        config(['incident-response.safety.always_backup_before_destructive' => true]);

        $this->site = Site::factory()->create();
        $this->response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
            'backup_created' => false,
        ]);
    }

    /** @return array{0: IncidentActionExecutor, 1: PluginManagerService&MockObject} */
    private function makeExecutor(): array
    {
        $pluginManager = $this->createMock(PluginManagerService::class);

        $executor = new IncidentActionExecutor(
            $this->createMockApiFactory(),
            $pluginManager,
            $this->createMock(SafeUpdateService::class),
            $this->createMock(DatabaseCleanupService::class),
        );

        return [$executor, $pluginManager];
    }

    private function giveSiteBackupConfig(): void
    {
        $destination = StorageDestination::factory()->create();
        BackupConfig::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
        ]);
    }

    private function makeVerifiedBackup(): Backup
    {
        return Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => \App\Enums\BackupStatus::Completed,
            'verification_status' => 'passed',
            'completed_at' => now(),
        ]);
    }

    public function test_destructive_action_refused_when_site_has_no_backup_config(): void
    {
        Bus::fake();
        [$executor, $pluginManager] = $this->makeExecutor();
        // The site must never be mutated when no backup can be taken.
        $pluginManager->expects($this->never())->method('deactivatePlugin');

        $result = $executor->execute(
            $this->response, $this->site, 'deactivate_plugin', 'ai_agent', ['plugin_id' => 5]
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Refused', $result['error']);
        $this->assertFalse($this->response->fresh()->backup_created);
        $this->assertDatabaseHas('incident_response_actions', [
            'incident_response_id' => $this->response->id,
            'action_type' => 'deactivate_plugin',
            'status' => 'refused',
        ]);
        Bus::assertNotDispatchedSync(CreateBackup::class);
    }

    public function test_destructive_action_refused_when_backup_does_not_complete(): void
    {
        Bus::fake(); // CreateBackup is dispatched but does not run -> no verified Backup row
        $this->giveSiteBackupConfig();

        [$executor, $pluginManager] = $this->makeExecutor();
        $pluginManager->expects($this->never())->method('deactivatePlugin');

        $result = $executor->execute(
            $this->response, $this->site, 'deactivate_plugin', 'ai_agent', ['plugin_id' => 5]
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Refused', $result['error']);
        $this->assertFalse($this->response->fresh()->backup_created);
        Bus::assertDispatchedSync(CreateBackup::class); // it tried to back up first
    }

    public function test_destructive_action_proceeds_when_incident_already_backed_up(): void
    {
        $this->response->update(['backup_created' => true]);

        [$executor, $pluginManager] = $this->makeExecutor();
        $pluginManager->expects($this->once())
            ->method('deactivatePlugin')
            ->willReturn(['success' => true]);

        $result = $executor->execute(
            $this->response, $this->site, 'deactivate_plugin', 'ai_agent', ['plugin_id' => 5]
        );

        $this->assertTrue($result['success']);
    }

    public function test_create_backup_action_marks_created_only_with_verified_backup(): void
    {
        Bus::fake();
        $this->giveSiteBackupConfig();
        $backup = $this->makeVerifiedBackup();

        [$executor] = $this->makeExecutor();
        $result = $executor->execute($this->response, $this->site, 'create_backup', 'ai_agent');

        $this->assertTrue($result['success']);
        $fresh = $this->response->fresh();
        $this->assertTrue($fresh->backup_created);
        $this->assertSame($backup->id, $fresh->backup_id);
    }

    public function test_create_backup_action_fails_without_backup_config(): void
    {
        Bus::fake();
        [$executor] = $this->makeExecutor();

        $result = $executor->execute($this->response, $this->site, 'create_backup', 'ai_agent');

        $this->assertFalse($result['success']);
        $this->assertFalse($this->response->fresh()->backup_created);
    }

    public function test_stale_backup_does_not_satisfy_invariant(): void
    {
        Bus::fake();
        $this->giveSiteBackupConfig();
        // A completed+verified backup that predates the incident is not a recovery
        // point for the mutation we are about to make.
        Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => \App\Enums\BackupStatus::Completed,
            'verification_status' => 'passed',
            'completed_at' => $this->response->created_at->copy()->subDay(),
        ]);

        [$executor, $pluginManager] = $this->makeExecutor();
        $pluginManager->expects($this->never())->method('deactivatePlugin');

        $result = $executor->execute(
            $this->response, $this->site, 'deactivate_plugin', 'ai_agent', ['plugin_id' => 5]
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Refused', $result['error']);
    }
}
