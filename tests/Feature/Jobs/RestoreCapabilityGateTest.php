<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * C-10: a full restore uses the connector's atomic staged swap, so it must be
 * gated on the connector advertising `staged_restore`. If it doesn't, the job
 * refuses loudly instead of silently merging in place (which would leave the
 * restored files running against the old database). Capabilities are refreshed
 * on demand if they were never fetched.
 */
class RestoreCapabilityGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function invokeGate(RestoreBackup $job): void
    {
        $m = new \ReflectionMethod($job, 'assertRestoreCapabilities');
        $m->setAccessible(true);
        $m->invoke($job);
    }

    private function backupFor(Site $site): Backup
    {
        return Backup::factory()->create(['site_id' => $site->id]);
    }

    public function test_passes_when_the_connector_advertises_staged_restore(): void
    {
        $site = Site::factory()->create(['backup_capabilities' => ['staged_restore' => true]]);

        $this->invokeGate(new RestoreBackup($this->backupFor($site)));

        $this->expectNotToPerformAssertions(); // no throw = pass, no on-demand fetch needed
    }

    public function test_refreshes_capabilities_on_demand_and_passes(): void
    {
        $site = Site::factory()->create(['backup_capabilities' => null]);
        $fake = $this->fakeApi();
        $fake->script('getBackupCapabilities', ['success' => true, 'staged_restore' => true]);

        $this->invokeGate(new RestoreBackup($this->backupFor($site)));

        $fake->assertCalled('getBackupCapabilities');
        $this->assertTrue($site->fresh()->connectorSupports('staged_restore'), 'the fetched capability is persisted');
    }

    public function test_refuses_a_full_restore_when_the_connector_lacks_staged_restore(): void
    {
        $site = Site::factory()->create(['backup_capabilities' => null]);
        $this->fakeApi()->script('getBackupCapabilities', null); // an old connector

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support staged restore');

        $this->invokeGate(new RestoreBackup($this->backupFor($site)));
    }

    public function test_a_selective_restore_needs_no_capability(): void
    {
        $site = Site::factory()->create(['backup_capabilities' => null]);
        $fake = $this->fakeApi();

        // Selective (merge) restore — the gate must not even probe capabilities.
        $this->invokeGate(new RestoreBackup($this->backupFor($site), true, true, ['wp-config.php']));

        $fake->assertNotCalled('getBackupCapabilities');
    }
}
