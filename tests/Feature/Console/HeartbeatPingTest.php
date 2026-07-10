<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E-11: the external heartbeat is the dead-man's switch — it must ping when
 * configured and stay inert (never error) when it is not.
 */
class HeartbeatPingTest extends TestCase
{
    public function test_pings_the_configured_heartbeat_url(): void
    {
        Http::fake();
        config(['monitoring.heartbeat_url' => 'https://hc.example.com/ping/abc']);

        $this->artisan('monitoring:heartbeat')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example.com/ping/abc');
    }

    public function test_no_op_when_heartbeat_is_not_configured(): void
    {
        Http::fake();
        config(['monitoring.heartbeat_url' => null]);

        $this->artisan('monitoring:heartbeat')->assertSuccessful();

        Http::assertNothingSent();
    }
}
