<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IncidentResponseStatus;
use App\Models\IncidentResponse;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\DatabaseCleanupService;
use App\Services\IncidentResponse\IncidentActionExecutor;
use App\Services\PluginManagerService;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-45: incident-driven plugin updates must respect the per-site
 * safe_updates_enabled opt-in. A site that opted out must not be updated by a
 * vulnerability/incident action; a site that opted in still is.
 */
class IncidentSafeUpdateGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeExecutor(SafeUpdateService $safeUpdateService): IncidentActionExecutor
    {
        $executor = new IncidentActionExecutor(
            $this->createMockApiFactory(),
            $this->createMock(PluginManagerService::class),
            $safeUpdateService,
            $this->createMock(DatabaseCleanupService::class),
        );

        // update_plugin is a mutating action — permit it so the opt-in gate (not
        // the allowlist gate) is what's under test.
        $executor->setAllowedActions(['update_plugin']);

        return $executor;
    }

    public function test_site_opted_out_is_not_updated(): void
    {
        $site = Site::factory()->create(['safe_updates_enabled' => false]);
        $plugin = SitePlugin::factory()->create([
            'site_id' => $site->id,
            'has_update' => true,
            'update_version' => '9.9.9',
        ]);
        // Backup already present so the destructive-action invariant is satisfied.
        $response = IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Executing,
            'backup_created' => true,
        ]);

        $safeUpdateService = $this->createMock(SafeUpdateService::class);
        $safeUpdateService->expects($this->never())->method('createSafeUpdate');

        $executor = $this->makeExecutor($safeUpdateService);
        $result = $executor->execute($response, $site, 'update_plugin', 'ai_agent', [
            'plugin_id' => $plugin->id,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('safe_updates_enabled', $result['error']);
    }

    public function test_site_opted_in_still_updates(): void
    {
        $site = Site::factory()->create(['safe_updates_enabled' => true]);
        $plugin = SitePlugin::factory()->create([
            'site_id' => $site->id,
            'has_update' => true,
            'update_version' => '9.9.9',
        ]);
        $response = IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Executing,
            'backup_created' => true,
        ]);

        $safeUpdate = SafeUpdate::factory()->completed()->create(['site_id' => $site->id]);

        $safeUpdateService = $this->createMock(SafeUpdateService::class);
        $safeUpdateService->expects($this->once())
            ->method('createSafeUpdate')
            ->willReturn($safeUpdate);
        $safeUpdateService->expects($this->once())->method('runSafeUpdate');

        $executor = $this->makeExecutor($safeUpdateService);
        $result = $executor->execute($response, $site, 'update_plugin', 'ai_agent', [
            'plugin_id' => $plugin->id,
        ]);

        $this->assertTrue($result['success']);
    }
}
