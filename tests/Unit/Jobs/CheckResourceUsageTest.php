<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CheckResourceUsage;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\ResourceCheck;
use App\Services\ResourceMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class CheckResourceUsageTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_job_fetches_and_stores_resource_data(): void
    {
        $site = $this->createSite();

        $resourceCheck = ResourceCheck::create([
            'site_id' => $site->id,
            'cpu_usage' => 25.5,
            'memory_used' => 2000000000,
            'memory_total' => 8000000000,
            'memory_percentage' => 25.0,
            'disk_used' => 40000000000,
            'disk_total' => 100000000000,
            'disk_percentage' => 40.0,
            'is_available' => true,
            'checked_at' => now(),
        ]);

        $mock = $this->mock(ResourceMonitorService::class);
        $mock->shouldReceive('fetchAndStore')
            ->once()
            ->with(\Mockery::on(fn ($arg) => $arg->id === $site->id))
            ->andReturn($resourceCheck);
        $mock->shouldReceive('checkThresholds')
            ->once()
            ->with(\Mockery::on(fn ($arg) => $arg->id === $resourceCheck->id))
            ->andReturn([]);

        $job = new CheckResourceUsage($site);
        $job->handle($mock);

        $this->assertDatabaseHas('resource_checks', [
            'site_id' => $site->id,
            'cpu_usage' => 25.5,
        ]);
    }

    public function test_job_checks_thresholds_and_sends_notifications(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $site = $this->createSite();

        // Create notification channels so notifications actually dispatch
        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'type' => 'email',
            'event_subscriptions' => null,
        ]);

        $resourceCheck = ResourceCheck::create([
            'site_id' => $site->id,
            'cpu_usage' => 95.0,
            'memory_used' => 7500000000,
            'memory_total' => 8000000000,
            'memory_percentage' => 93.75,
            'disk_used' => 95000000000,
            'disk_total' => 100000000000,
            'disk_percentage' => 95.0,
            'is_available' => true,
            'checked_at' => now(),
        ]);

        $serviceMock = $this->mock(ResourceMonitorService::class);
        $serviceMock->shouldReceive('fetchAndStore')
            ->once()
            ->andReturn($resourceCheck);
        $serviceMock->shouldReceive('checkThresholds')
            ->once()
            ->andReturn(['disk_space_critical', 'memory_critical', 'cpu_warning']);

        $job = new CheckResourceUsage($site);
        $job->handle($serviceMock);

        // Each violation triggers NotificationService::notifySiteEvent which dispatches SendNotificationJob
        Bus::assertDispatched(SendNotificationJob::class, 3);
    }
}
