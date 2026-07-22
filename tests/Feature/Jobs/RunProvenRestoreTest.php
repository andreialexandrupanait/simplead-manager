<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\RunProvenRestore;
use App\Models\Backup;
use App\Models\ProvenRestore;
use App\Models\Site;
use App\Services\Backup\SandboxRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * C-08: the weekly proven-restore job picks the pilot site due for a proof,
 * restores its latest backup into the sandbox, health-checks it, and records the
 * outcome. The heavy restore/health-check work (SandboxRestoreService) is mocked
 * so these assert only the orchestration/rotation/recording contract.
 */
class RunProvenRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // suppress FetchSiteFavicon + alert channel fan-out
    }

    private function sandbox(): Site
    {
        return Site::factory()->create(['is_sandbox' => true, 'url' => 'http://sandbox-wp']);
    }

    private function enabledSite(): Site
    {
        return Site::factory()->create(['proven_restore_enabled' => true, 'is_sandbox' => false]);
    }

    private function completedBackup(Site $site): Backup
    {
        return Backup::factory()->create(['site_id' => $site->id, 'status' => BackupStatus::Completed]);
    }

    private function mockService(array $result): void
    {
        $mock = Mockery::mock(SandboxRestoreService::class);
        $mock->shouldReceive('prove')->andReturn($result);
        $this->app->instance(SandboxRestoreService::class, $mock);
    }

    public function test_a_passing_proof_is_recorded_without_an_alert(): void
    {
        $this->sandbox();
        $site = $this->enabledSite();
        $backup = $this->completedBackup($site);
        $this->mockService(['passed' => true, 'checks' => ['homepage_200' => true], 'error' => null]);

        (new RunProvenRestore)->handle(app(SandboxRestoreService::class));

        $this->assertDatabaseHas('proven_restores', [
            'site_id' => $site->id,
            'backup_id' => $backup->id,
            'status' => ProvenRestore::STATUS_PASSED,
        ]);
    }

    public function test_a_failing_proof_is_recorded_and_alerts(): void
    {
        $this->sandbox();
        $site = $this->enabledSite();
        $this->completedBackup($site);
        $this->mockService(['passed' => false, 'checks' => ['homepage_200' => false], 'error' => null]);

        (new RunProvenRestore)->handle(app(SandboxRestoreService::class));

        $this->assertDatabaseHas('proven_restores', [
            'site_id' => $site->id,
            'status' => ProvenRestore::STATUS_FAILED,
        ]);
    }

    public function test_records_a_failure_when_the_site_has_no_completed_backup(): void
    {
        $this->sandbox();
        $site = $this->enabledSite();
        // A SandboxRestoreService mock that must NEVER be called (no backup to prove).
        $mock = Mockery::mock(SandboxRestoreService::class);
        $mock->shouldNotReceive('prove');
        $this->app->instance(SandboxRestoreService::class, $mock);

        (new RunProvenRestore)->handle(app(SandboxRestoreService::class));

        $this->assertDatabaseHas('proven_restores', [
            'site_id' => $site->id,
            'backup_id' => null,
            'status' => ProvenRestore::STATUS_FAILED,
        ]);
    }

    public function test_skips_cleanly_when_no_sandbox_is_provisioned(): void
    {
        $site = $this->enabledSite();
        $this->completedBackup($site);

        (new RunProvenRestore)->handle(app(SandboxRestoreService::class));

        $this->assertDatabaseCount('proven_restores', 0);
    }

    public function test_rotation_picks_the_site_longest_without_a_proof(): void
    {
        $this->sandbox();
        $recent = $this->enabledSite();
        $stale = $this->enabledSite();
        $this->completedBackup($recent);
        $this->completedBackup($stale);

        ProvenRestore::factory()->create(['site_id' => $recent->id, 'ran_at' => now()->subDay()]);
        ProvenRestore::factory()->create(['site_id' => $stale->id, 'ran_at' => now()->subMonth()]);

        $captured = null;
        $mock = Mockery::mock(SandboxRestoreService::class);
        $mock->shouldReceive('prove')->andReturnUsing(function ($sandbox, $backup) use (&$captured) {
            $captured = $backup->site_id;

            return ['passed' => true, 'checks' => [], 'error' => null];
        });
        $this->app->instance(SandboxRestoreService::class, $mock);

        (new RunProvenRestore)->handle(app(SandboxRestoreService::class));

        $this->assertSame($stale->id, $captured, 'the least-recently-proven site must be picked');
    }
}
