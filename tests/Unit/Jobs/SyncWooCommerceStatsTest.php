<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SyncWooCommerceStats;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class SyncWooCommerceStatsTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_job_delegates_to_woocommerce_service(): void
    {
        $site = $this->createSite();

        $mock = $this->mock(WooCommerceService::class);
        $mock->shouldReceive('syncDailyStats')
            ->once()
            ->with(\Mockery::on(fn ($arg) => $arg->id === $site->id));
        $mock->shouldReceive('checkAlerts')
            ->once()
            ->with(\Mockery::on(fn ($arg) => $arg->id === $site->id));

        $job = new SyncWooCommerceStats($site);
        $job->handle($mock);
    }
}
