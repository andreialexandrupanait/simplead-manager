<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\SafeUpdate;
use App\Models\Site;
use App\Services\RollbackService;
use App\Services\SafeUpdateService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafeUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public function test_create_safe_update_creates_pending_record(): void
    {
        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);
        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory(), $screenshotService);

        $update = $service->createSafeUpdate($this->site, 'plugin', 'yoast-seo', 'Yoast SEO', '20.0', '21.0');

        $this->assertSame('pending', $update->status);
        $this->assertSame('plugin', $update->type);
        $this->assertSame('yoast-seo', $update->slug);
        $this->assertDatabaseHas('safe_updates', ['id' => $update->id]);
    }

    public function test_create_safe_update_persists_target(): void
    {
        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);
        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory(), $screenshotService);

        $update = $service->createSafeUpdate(
            $this->site, 'plugin', 'akismet', 'Akismet', '5.0', '5.1', 'akismet/akismet.php'
        );

        $this->assertSame('akismet/akismet.php', $update->target);
        $this->assertDatabaseHas('safe_updates', ['id' => $update->id, 'target' => 'akismet/akismet.php']);
    }

    public function test_run_safe_update_sends_target_and_fails_when_connector_rejects(): void
    {
        // Regression for the P0: a bare slug is rejected by the connector as an
        // invalid plugin path. The update must send the plugin file, and a
        // rejected update must be recorded as FAILED — never a false success.
        $api = $this->createMockApi();
        $api->expects($this->once())
            ->method('updatePlugins')
            ->with(['akismet/akismet.php'])
            ->willReturn([
                'results' => [
                    'akismet/akismet.php' => ['success' => false, 'error' => 'Invalid plugin path.'],
                ],
            ]);
        // The update failed, so no health check or rollback point should happen.
        $api->expects($this->never())->method('healthCheck');

        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->expects($this->never())->method('createRollbackPoint');

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'target' => 'akismet/akismet.php',
            'name' => 'Akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertSame('failed', $safeUpdate->status);
        $this->assertStringContainsString('Invalid plugin path', (string) $safeUpdate->error_message);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'slug' => 'akismet',
            'success' => false,
        ]);
    }

    public function test_run_safe_update_completes_on_healthy(): void
    {
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn([
            'results' => ['yoast-seo' => ['success' => true, 'to_version' => '21.0']],
        ]);
        $api->method('healthCheck')->willReturn(['status' => 'ok', 'checks' => []]);

        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->method('createRollbackPoint')->willReturn(
            \App\Models\RollbackPoint::factory()->make(['id' => 1])
        );

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertSame('completed', $safeUpdate->status);
        $this->assertNotNull($safeUpdate->completed_at);
    }

    public function test_run_safe_update_fails_on_health_check_failure(): void
    {
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn([
            'results' => ['yoast-seo' => ['success' => true]],
        ]);
        $api->method('healthCheck')->willReturn([
            'status' => 'error',
            'checks' => [['name' => 'db', 'status' => 'error']],
        ]);

        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->method('createRollbackPoint')->willReturn(
            \App\Models\RollbackPoint::factory()->make(['id' => 1])
        );

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
            'auto_rollback' => false,
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertSame('failed', $safeUpdate->status);
        $this->assertStringContainsString('Health check failed', $safeUpdate->error_message);
    }

    public function test_run_safe_update_triggers_rollback_on_auto_rollback(): void
    {
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn([
            'results' => ['yoast-seo' => ['success' => true]],
        ]);
        $api->method('healthCheck')->willReturn(['status' => 'error', 'checks' => []]);

        $rollbackPoint = \App\Models\RollbackPoint::factory()->make(['id' => 1]);
        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->method('createRollbackPoint')->willReturn($rollbackPoint);
        $rollbackService->expects($this->once())->method('executeRollback')->with($rollbackPoint);

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
            'auto_rollback' => true,
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertSame('failed', $safeUpdate->status);
    }

    public function test_run_safe_update_exception_marks_failed(): void
    {
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willThrowException(new \RuntimeException('API down'));

        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $service->runSafeUpdate($safeUpdate);
        } finally {
            $safeUpdate->refresh();
            $this->assertSame('failed', $safeUpdate->status);
            $this->assertNotNull($safeUpdate->completed_at);
        }
    }

    public function test_run_health_checks_returns_false_on_exception(): void
    {
        $api = $this->createMockApi();
        $api->method('healthCheck')->willThrowException(new \RuntimeException('503'));

        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);
        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $result = $service->runHealthChecks($this->site);

        $this->assertFalse($result['passed']);
    }
}
