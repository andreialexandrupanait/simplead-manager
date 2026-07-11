<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The agent-pull API (and its AuthenticateAgent middleware) was removed —
 * no WordPress-side poller ever shipped. The deterministic api_key_hash
 * column is deliberately kept in sync so keys stay encrypted at rest yet
 * queryable if an agent flow ever returns.
 */
class AgentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_api_key_keeps_the_lookup_hash_in_sync(): void
    {
        $site = Site::factory()->create(['api_key' => 'token-one']);
        $this->assertSame(hash('sha256', 'token-one'), $site->fresh()->api_key_hash);

        $site->update(['api_key' => 'token-two']);
        $this->assertSame(hash('sha256', 'token-two'), $site->fresh()->api_key_hash);

        $site->update(['api_key' => null]);
        $this->assertNull($site->fresh()->api_key_hash);
    }

    public function test_the_agent_pull_routes_are_gone(): void
    {
        $this->get('/api/agent/some-token/security/pending-commands')->assertNotFound();
    }
}
