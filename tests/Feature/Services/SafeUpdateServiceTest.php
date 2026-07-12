<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\SafeUpdate;
use App\Models\Site;
use App\Services\RollbackService;
use App\Services\SafeUpdateService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    /**
     * Build a service whose pre-update backup is stubbed to a verified-completed
     * Backup so tests can exercise the update/health/rollback stages. A real
     * backup config is created so the P1-17 no-config abort is not triggered.
     */
    private function serviceWithCompletedBackup(
        RollbackService $rollbackService,
        \App\Contracts\WordPressApiServiceInterface $api,
        ScreenshotService $screenshotService,
    ): SafeUpdateService {
        \App\Models\BackupConfig::factory()->create(['site_id' => $this->site->id]);

        return new class($rollbackService, $this->createMockApiFactory($api), $screenshotService) extends SafeUpdateService
        {
            protected function runPreUpdateBackup(\App\Models\Site $site, \App\Models\BackupConfig $config, ?string $heldLockToken): ?\App\Models\Backup
            {
                return \App\Models\Backup::factory()->make(['status' => \App\Enums\BackupStatus::Completed]);
            }
        };
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

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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
        $rollbackService->expects($this->once())->method('executeRollback')->with($rollbackPoint, null)->willReturn(['success' => true]);

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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

    public function test_run_safe_update_hard_aborts_when_pre_update_backup_does_not_complete(): void
    {
        // P0-07: the pre-update safety backup was skipped/failed (e.g. lock
        // contention). The service must HARD-ABORT — never update a client site
        // with no rollback point — and never call the update endpoint.
        $api = $this->createMockApi();
        $api->expects($this->never())->method('updatePlugins');

        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);

        $site = $this->site;
        \App\Models\BackupConfig::factory()->create(['site_id' => $site->id]);

        $service = new class($rollbackService, $this->createMockApiFactory($api), $screenshotService) extends SafeUpdateService
        {
            protected function runPreUpdateBackup(\App\Models\Site $site, \App\Models\BackupConfig $config, ?string $heldLockToken): ?\App\Models\Backup
            {
                return null; // simulate a skipped / failed pre-update backup
            }
        };

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'target' => 'wordpress-seo/wp-seo.php',
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
            $this->assertStringContainsString('Pre-update safety backup did not complete', (string) $safeUpdate->error_message);
        }
    }

    public function test_run_safe_update_proceeds_when_pre_update_backup_completes(): void
    {
        // The mirror case: a verified-completed pre-update backup lets the
        // update proceed normally.
        $api = $this->createMockApi();
        $api->expects($this->once())->method('updatePlugins')->willReturn([
            'results' => ['wordpress-seo/wp-seo.php' => ['success' => true, 'to_version' => '21.0']],
        ]);
        $api->method('healthCheck')->willReturn(['status' => 'ok', 'checks' => []]);

        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->method('createRollbackPoint')->willReturn(
            \App\Models\RollbackPoint::factory()->make(['id' => 1])
        );
        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $site = $this->site;
        \App\Models\BackupConfig::factory()->create(['site_id' => $site->id]);

        $service = new class($rollbackService, $this->createMockApiFactory($api), $screenshotService) extends SafeUpdateService
        {
            protected function runPreUpdateBackup(\App\Models\Site $site, \App\Models\BackupConfig $config, ?string $heldLockToken): ?\App\Models\Backup
            {
                return \App\Models\Backup::factory()->make(['status' => \App\Enums\BackupStatus::Completed]);
            }
        };

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'target' => 'wordpress-seo/wp-seo.php',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        $this->assertSame('completed', $safeUpdate->fresh()->status);
    }

    public function test_run_safe_update_hard_aborts_when_site_has_no_backup_config(): void
    {
        // P1-17: with no backup configuration there is no way to take a pre-update
        // safety backup. The previous code silently skipped the backup and updated
        // the site anyway. It must now hard-abort and never call the update.
        $api = $this->createMockApi();
        $api->expects($this->never())->method('updatePlugins');

        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);
        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        // $this->site intentionally has NO backupConfig.
        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'target' => 'wordpress-seo/wp-seo.php',
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
            $this->assertStringContainsString('No backup configuration', (string) $safeUpdate->error_message);
        }
    }

    public function test_pre_update_backup_is_full_and_dispatched_synchronously(): void
    {
        // P1-17/P1-18: the safety backup must cover files + database (type "full",
        // not DB-only) and must run synchronously (dispatchSync) so it completes
        // before the update. Bus::fake records the sync dispatch without running it,
        // so no Backup row is produced and the flow then hard-aborts (verified too).
        Bus::fake();

        $api = $this->createMockApi();
        $api->expects($this->never())->method('updatePlugins');

        $rollbackService = $this->createMock(RollbackService::class);
        $screenshotService = $this->createMock(ScreenshotService::class);
        \App\Models\BackupConfig::factory()->create(['site_id' => $this->site->id]);

        $service = new SafeUpdateService($rollbackService, $this->createMockApiFactory($api), $screenshotService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'target' => 'wordpress-seo/wp-seo.php',
            'name' => 'Yoast SEO',
            'from_version' => '20.0',
            'to_version' => '21.0',
            'status' => 'pending',
        ]);

        try {
            $service->runSafeUpdate($safeUpdate);
        } catch (\RuntimeException) {
            // expected: no Backup row produced under Bus::fake → hard abort
        }

        Bus::assertDispatchedSync(
            \App\Jobs\CreateBackup::class,
            fn ($job) => $job->type === 'full'
                && $job->trigger === 'pre_update'
                && $job->site->id === $this->site->id,
        );
    }

    public function test_health_check_failure_notifies_operator(): void
    {
        // P1-42: a post-update health-check failure must not complete silently.
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn(['results' => ['yoast-seo' => ['success' => true]]]);
        $api->method('healthCheck')->willReturn(['status' => 'error', 'checks' => []]);

        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->method('createRollbackPoint')->willReturn(
            \App\Models\RollbackPoint::factory()->make(['id' => 1])
        );
        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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

        $this->assertSame('failed', $safeUpdate->fresh()->status);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->site->user_id,
            'type' => 'critical',
        ]);
    }

    public function test_auto_rollback_notifies_operator(): void
    {
        // P1-42: when we automatically roll a client site back, page someone.
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn(['results' => ['yoast-seo' => ['success' => true]]]);
        $api->method('healthCheck')->willReturn(['status' => 'error', 'checks' => []]);

        $rollbackPoint = \App\Models\RollbackPoint::factory()->make(['id' => 1]);
        $rollbackService = $this->createMock(RollbackService::class);
        $rollbackService->method('createRollbackPoint')->willReturn($rollbackPoint);
        $rollbackService->method('executeRollback')->willReturn(['success' => true]);

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('capture')->willReturn(null);

        $service = $this->serviceWithCompletedBackup($rollbackService, $api, $screenshotService);

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

        $this->assertSame('failed', $safeUpdate->fresh()->status);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->site->user_id,
            'type' => 'critical',
        ]);
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
