<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProbeSiteReconnection;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ProbeSiteReconnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
    }

    public function test_probe_reconnects_a_recovered_site(): void
    {
        $site = Site::factory()->create(['is_connected' => false]);

        $api = $this->createMockApi();
        $api->expects($this->once())
            ->method('getInfo')
            ->willReturn(['wp_version' => '6.5']);

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new ProbeSiteReconnection($site))->handle(app(WordPressApiServiceFactory::class));

        $this->assertTrue($site->fresh()->is_connected, 'A reachable site must be flipped back to connected.');
    }

    public function test_probe_leaves_still_unreachable_site_disconnected(): void
    {
        $site = Site::factory()->create(['is_connected' => false]);

        $api = $this->createMockApi();
        $api->method('getInfo')->willThrowException(new \RuntimeException('still down'));

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new ProbeSiteReconnection($site))->handle(app(WordPressApiServiceFactory::class));

        $this->assertFalse($site->fresh()->is_connected, 'An unreachable site must stay disconnected.');
    }

    public function test_probe_is_a_noop_when_site_already_connected(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $api = $this->createMockApi();
        // getInfo must NOT be called — the probe should short-circuit.
        $api->expects($this->never())->method('getInfo');

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new ProbeSiteReconnection($site))->handle(app(WordPressApiServiceFactory::class));

        $this->assertTrue($site->fresh()->is_connected);
    }
}
