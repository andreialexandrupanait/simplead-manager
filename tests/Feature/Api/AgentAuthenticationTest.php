<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SC-A2-03: agent auth previously matched the plaintext token against the
 * encrypted api_key column — structurally impossible, every call 401'd.
 * The middleware now looks up sites via the deterministic api_key_hash.
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

    public function test_agent_request_with_valid_token_and_signature_authenticates(): void
    {
        $site = Site::factory()->create([
            'api_key' => 'agent-token-123',
            'api_secret' => 'agent-secret-456',
        ]);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.', $site->api_secret);

        // Plain get(): the json() helpers send a "[]" body, which would change
        // the HMAC payload ($timestamp.'.'.$content).
        $this->get('/api/agent/agent-token-123/security/pending-commands', [
            'Accept' => 'application/json',
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
        ])->assertOk();
    }

    public function test_agent_request_with_unknown_token_is_rejected(): void
    {
        $this->get('/api/agent/nope/security/pending-commands', [
            'Accept' => 'application/json',
            'X-Signature' => 'x',
            'X-Timestamp' => (string) time(),
        ])->assertUnauthorized();
    }

    public function test_agent_request_with_bad_signature_is_rejected(): void
    {
        Site::factory()->create([
            'api_key' => 'agent-token-123',
            'api_secret' => 'agent-secret-456',
        ]);

        $this->get('/api/agent/agent-token-123/security/pending-commands', [
            'Accept' => 'application/json',
            'X-Signature' => hash_hmac('sha256', 'tampered', 'wrong-secret'),
            'X-Timestamp' => (string) time(),
        ])->assertUnauthorized();
    }
}
