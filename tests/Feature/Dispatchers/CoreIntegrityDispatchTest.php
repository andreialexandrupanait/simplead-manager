<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\MonitoringDispatcher;
use App\Jobs\CheckCoreFileIntegrity;
use App\Models\SecurityMonitor;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faza 2 gap: core file integrity used to run ONLY on a manual click in the
 * Security tab, so it was never produced for a site nobody opened. It now rides
 * the automated security-scan cadence (connected site + active SecurityMonitor).
 */
class CoreIntegrityDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_due_security_scan_also_dispatches_core_integrity(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SecurityMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'interval_minutes' => 60,
            'next_scan_at' => now()->subMinute(),
        ]);

        (new MonitoringDispatcher)();

        Queue::assertPushed(
            CheckCoreFileIntegrity::class,
            fn (CheckCoreFileIntegrity $job) => $job->site->id === $site->id,
        );
    }

    public function test_integrity_is_not_dispatched_for_a_disconnected_site(): void
    {
        $site = Site::factory()->create(['is_connected' => false]);
        SecurityMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'interval_minutes' => 60,
            'next_scan_at' => now()->subMinute(),
        ]);

        (new MonitoringDispatcher)();

        Queue::assertNotPushed(CheckCoreFileIntegrity::class);
    }

    public function test_open_circuit_suppresses_integrity(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $site->healthState()->update(['circuit_state' => 'open']);
        SecurityMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'interval_minutes' => 60,
            'next_scan_at' => now()->subMinute(),
        ]);

        (new MonitoringDispatcher)();

        Queue::assertNotPushed(CheckCoreFileIntegrity::class);
    }
}
