<?php

namespace Tests\Unit\Services;

use App\Jobs\CreateBackup;
use App\Models\BackupConfig;
use App\Models\SafeUpdate;
use App\Models\StorageDestination;
use App\Models\UpdateLog;
use App\Services\RollbackService;
use App\Services\SafeUpdateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SafeUpdateServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    // ------------------------------------------------------------------ //
    //  createSafeUpdate
    // ------------------------------------------------------------------ //

    public function test_create_safe_update_creates_record_with_pending_status(): void
    {
        $site = $this->createSite();
        $rollbackService = new RollbackService();
        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = $service->createSafeUpdate(
            $site, 'plugin', 'akismet', 'Akismet', '5.0', '5.1'
        );

        $this->assertInstanceOf(SafeUpdate::class, $safeUpdate);
        $this->assertDatabaseHas('safe_updates', [
            'id' => $safeUpdate->id,
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'name' => 'Akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'status' => 'pending',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  runSafeUpdate — successful flow
    // ------------------------------------------------------------------ //

    public function test_run_safe_update_successful_flow_sets_completed(): void
    {
        $site = $this->createSite();
        Http::fake([
            '*/wp-json/simplead/v1/plugins/update' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/health' => Http::response([
                'status' => 'ok',
                'checks' => [['name' => 'site', 'status' => 'ok']],
            ]),
            '*' => Http::response([]),
        ]);

        $rollbackService = $this->mock(RollbackService::class);
        $rollbackService->shouldReceive('createRollbackPoint')
            ->once()
            ->andReturn(\App\Models\RollbackPoint::factory()->make([
                'site_id' => $site->id,
            ]));

        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'name' => 'Akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertEquals('completed', $safeUpdate->status);
        $this->assertNotNull($safeUpdate->completed_at);
    }

    // ------------------------------------------------------------------ //
    //  runSafeUpdate — health check passes
    // ------------------------------------------------------------------ //

    public function test_successful_health_check_sets_status_to_completed(): void
    {
        $site = $this->createSite();
        Http::fake([
            '*/wp-json/simplead/v1/plugins/update' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/health' => Http::response([
                'status' => 'ok',
                'checks' => [['name' => 'database', 'status' => 'ok']],
            ]),
            '*' => Http::response([]),
        ]);

        $rollbackService = $this->mock(RollbackService::class);
        $rollbackService->shouldReceive('createRollbackPoint')
            ->once()
            ->andReturn(\App\Models\RollbackPoint::factory()->make([
                'site_id' => $site->id,
            ]));

        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'woocommerce',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertEquals('completed', $safeUpdate->status);
        $this->assertNotNull($safeUpdate->health_check_results);
    }

    // ------------------------------------------------------------------ //
    //  runSafeUpdate — health check fails
    // ------------------------------------------------------------------ //

    public function test_failed_health_check_sets_status_to_failed(): void
    {
        $site = $this->createSite();
        Http::fake([
            '*/wp-json/simplead/v1/plugins/update' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/health' => Http::response([
                'status' => 'error',
                'checks' => [['name' => 'database', 'status' => 'error']],
            ]),
            '*' => Http::response([]),
        ]);

        $rollbackService = $this->mock(RollbackService::class);
        $rollbackService->shouldReceive('createRollbackPoint')
            ->once()
            ->andReturn(\App\Models\RollbackPoint::factory()->make([
                'site_id' => $site->id,
            ]));

        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'woocommerce',
            'status' => 'pending',
            'auto_rollback' => false,
        ]);

        $service->runSafeUpdate($safeUpdate);

        $safeUpdate->refresh();
        $this->assertEquals('failed', $safeUpdate->status);
        $this->assertEquals('Health check failed after update', $safeUpdate->error_message);
    }

    // ------------------------------------------------------------------ //
    //  runSafeUpdate — exception during update
    // ------------------------------------------------------------------ //

    public function test_exception_during_update_sets_status_to_failed(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/plugins/update' => Http::response('Server Error', 500),
            '*' => Http::response([]),
        ]);

        $rollbackService = $this->mock(RollbackService::class);
        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'status' => 'pending',
        ]);

        try {
            $service->runSafeUpdate($safeUpdate);
        } catch (\Exception $e) {
            // Expected — the service re-throws after marking as failed
        }

        $safeUpdate->refresh();
        $this->assertEquals('failed', $safeUpdate->status);
        $this->assertNotNull($safeUpdate->error_message);
    }

    // ------------------------------------------------------------------ //
    //  runHealthChecks — passes
    // ------------------------------------------------------------------ //

    public function test_run_health_checks_returns_passed_true_when_ok(): void
    {
        $site = $this->createSite();
        Http::fake([
            '*/wp-json/simplead/v1/health' => Http::response([
                'status' => 'ok',
                'checks' => [['name' => 'site', 'status' => 'ok']],
            ]),
            '*' => Http::response([]),
        ]);

        $service = new SafeUpdateService(new RollbackService());
        $result = $service->runHealthChecks($site);

        $this->assertTrue($result['passed']);
    }

    // ------------------------------------------------------------------ //
    //  runHealthChecks — fails on API exception
    // ------------------------------------------------------------------ //

    public function test_run_health_checks_returns_passed_false_on_exception(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/health' => Http::response('Server Error', 500),
            '*' => Http::response([]),
        ]);

        $service = new SafeUpdateService(new RollbackService());
        $result = $service->runHealthChecks($site);

        $this->assertFalse($result['passed']);
    }

    // ------------------------------------------------------------------ //
    //  runSafeUpdate — backup is created when config exists
    // ------------------------------------------------------------------ //

    public function test_run_safe_update_dispatches_backup_when_config_exists(): void
    {
        Bus::fake([CreateBackup::class]);

        $site = $this->createSite();

        $destination = StorageDestination::create([
            'name' => 'Local',
            'type' => 'local',
            'config' => ['path' => '/backups'],
            'is_default' => true,
            'is_active' => true,
        ]);
        BackupConfig::create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'is_enabled' => true,
            'frequency' => 'daily',
            'time' => '02:00',
            'type' => 'full',
        ]);

        Http::fake([
            '*/wp-json/simplead/v1/plugins/update' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/health' => Http::response([
                'status' => 'ok',
                'checks' => [],
            ]),
            '*' => Http::response([]),
        ]);

        $rollbackService = $this->mock(RollbackService::class);
        $rollbackService->shouldReceive('createRollbackPoint')
            ->once()
            ->andReturn(\App\Models\RollbackPoint::factory()->make([
                'site_id' => $site->id,
            ]));

        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'woocommerce',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        Bus::assertDispatchedSync(CreateBackup::class);
    }

    // ------------------------------------------------------------------ //
    //  runSafeUpdate — creates UpdateLog on success
    // ------------------------------------------------------------------ //

    public function test_run_safe_update_creates_update_log(): void
    {
        $site = $this->createSite();
        Http::fake([
            '*/wp-json/simplead/v1/plugins/update' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/health' => Http::response([
                'status' => 'ok',
                'checks' => [],
            ]),
            '*' => Http::response([]),
        ]);

        $rollbackService = $this->mock(RollbackService::class);
        $rollbackService->shouldReceive('createRollbackPoint')
            ->once()
            ->andReturn(\App\Models\RollbackPoint::factory()->make([
                'site_id' => $site->id,
            ]));

        $service = new SafeUpdateService($rollbackService);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'name' => 'Akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'status' => 'pending',
        ]);

        $service->runSafeUpdate($safeUpdate);

        $this->assertDatabaseHas('update_logs', [
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'success' => true,
        ]);
    }
}
