<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\ProbeSiteReconnection;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ReconnectProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
    }

    public function test_it_dispatches_probes_only_for_disconnected_sites(): void
    {
        $disconnected = Site::factory()->create(['is_connected' => false]);
        Site::factory()->create(['is_connected' => true]);

        $this->artisan('sites:reconnect-probe')->assertSuccessful();

        Queue::assertPushed(ProbeSiteReconnection::class, 1);
        Queue::assertPushed(
            ProbeSiteReconnection::class,
            fn (ProbeSiteReconnection $job) => $job->site->is($disconnected),
        );
    }

    public function test_it_is_a_noop_when_all_sites_connected(): void
    {
        Site::factory()->count(2)->create(['is_connected' => true]);

        $this->artisan('sites:reconnect-probe')->assertSuccessful();

        Queue::assertNotPushed(ProbeSiteReconnection::class);
    }
}
