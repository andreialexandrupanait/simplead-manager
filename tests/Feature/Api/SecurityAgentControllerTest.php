<?php

namespace Tests\Feature\Api;

use App\Enums\SecurityCommandPriority;
use App\Enums\SecurityCommandStatus;
use App\Models\SecurityCommand;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $apiKey = 'test-api-key-for-agent-tests';

    private string $apiSecret = 'test-api-secret-for-agent-tests';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->site = Site::factory()->for($user)->create();

        // Store raw api_key (bypassing encrypted cast) so WHERE clause matches
        DB::table('sites')->where('id', $this->site->id)->update([
            'api_key' => $this->apiKey,
            'api_secret' => Crypt::encryptString($this->apiSecret),
        ]);
        $this->site->refresh();
    }

    private function signedHeaders(string $body = '[]'): array
    {
        $timestamp = (string) time();
        $payload = $timestamp.'.'.$body;
        $signature = hash_hmac('sha256', $payload, $this->apiSecret);

        return [
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
        ];
    }

    private function createCommand(array $overrides = []): SecurityCommand
    {
        $command = new SecurityCommand;
        $command->forceFill(array_merge([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'action' => 'disable_theme_editor',
            'payload' => ['enabled' => true],
            'priority' => SecurityCommandPriority::Normal,
            'status' => SecurityCommandStatus::Pending,
            'attempts' => 0,
            'max_attempts' => 3,
        ], $overrides));
        $command->save();

        return $command->fresh();
    }

    // ── Authentication ──

    #[Test]
    public function agent_api_rejects_missing_signature(): void
    {
        $response = $this->getJson("/api/agent/{$this->apiKey}/security/pending-commands");

        $response->assertStatus(401);
    }

    #[Test]
    public function agent_api_rejects_invalid_signature(): void
    {
        $response = $this->getJson("/api/agent/{$this->apiKey}/security/pending-commands", [
            'X-Signature' => 'invalid-signature',
            'X-Timestamp' => (string) time(),
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function agent_api_rejects_expired_timestamp(): void
    {
        $timestamp = (string) (time() - 600);
        $payload = $timestamp.'.';
        $signature = hash_hmac('sha256', $payload, $this->apiSecret);

        $response = $this->getJson("/api/agent/{$this->apiKey}/security/pending-commands", [
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function agent_api_rejects_invalid_site_token(): void
    {
        $response = $this->getJson('/api/agent/nonexistent-token/security/pending-commands', [
            'X-Signature' => 'any',
            'X-Timestamp' => (string) time(),
        ]);

        $response->assertStatus(401);
    }

    // ── Pending Commands ──

    #[Test]
    public function pending_commands_returns_pending_commands(): void
    {
        $command = $this->createCommand();

        $response = $this->getJson(
            "/api/agent/{$this->apiKey}/security/pending-commands",
            $this->signedHeaders()
        );

        $response->assertOk()
            ->assertJsonCount(1, 'commands')
            ->assertJsonPath('commands.0.id', $command->id)
            ->assertJsonPath('commands.0.category', 'hardening')
            ->assertJsonPath('commands.0.action', 'disable_theme_editor')
            ->assertJsonPath('commands.0.priority', 'normal');
    }

    #[Test]
    public function pending_commands_marks_as_picked_up(): void
    {
        $this->createCommand();

        $this->getJson(
            "/api/agent/{$this->apiKey}/security/pending-commands",
            $this->signedHeaders()
        );

        $this->assertDatabaseHas('security_commands', [
            'site_id' => $this->site->id,
            'status' => SecurityCommandStatus::PickedUp->value,
        ]);
    }

    #[Test]
    public function pending_commands_does_not_return_other_sites_commands(): void
    {
        $otherSite = Site::factory()->create();
        $this->createCommand(['site_id' => $otherSite->id]);

        $response = $this->getJson(
            "/api/agent/{$this->apiKey}/security/pending-commands",
            $this->signedHeaders()
        );

        $response->assertOk()
            ->assertJsonCount(0, 'commands');
    }

    #[Test]
    public function pending_commands_returns_empty_when_none(): void
    {
        $response = $this->getJson(
            "/api/agent/{$this->apiKey}/security/pending-commands",
            $this->signedHeaders()
        );

        $response->assertOk()
            ->assertJsonCount(0, 'commands');
    }

    // ── Command Results ──

    #[Test]
    public function command_results_processes_successful_result(): void
    {
        $command = $this->createCommand();

        $data = [
            'results' => [
                [
                    'command_id' => $command->id,
                    'success' => true,
                    'data' => ['applied' => true],
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/command-results",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertOk()
            ->assertJsonPath('processed', 1);

        $this->assertDatabaseHas('security_commands', [
            'id' => $command->id,
            'status' => SecurityCommandStatus::Completed->value,
        ]);
    }

    #[Test]
    public function command_results_processes_failed_result(): void
    {
        $command = $this->createCommand(['attempts' => 3, 'max_attempts' => 3]);

        $data = [
            'results' => [
                [
                    'command_id' => $command->id,
                    'success' => false,
                    'error' => 'File not writable',
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/command-results",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertOk()
            ->assertJsonPath('processed', 1);

        $this->assertDatabaseHas('security_commands', [
            'id' => $command->id,
            'status' => SecurityCommandStatus::Failed->value,
        ]);
    }

    #[Test]
    public function command_results_ignores_other_sites_commands(): void
    {
        $otherSite = Site::factory()->create();
        $command = $this->createCommand(['site_id' => $otherSite->id]);

        $data = [
            'results' => [
                [
                    'command_id' => $command->id,
                    'success' => true,
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/command-results",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertOk()
            ->assertJsonPath('processed', 0);
    }

    #[Test]
    public function command_results_validates_input(): void
    {
        $data = ['results' => 'not-an-array'];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/command-results",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertStatus(422);
    }

    // ── Activity Logs ──

    #[Test]
    public function activity_logs_ingests_valid_logs(): void
    {
        $data = [
            'logs' => [
                [
                    'event_type' => 'failed_login',
                    'username' => 'admin',
                    'ip_address' => '192.168.1.100',
                    'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/activity-logs",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertOk()
            ->assertJsonPath('ingested', 1);

        $this->assertDatabaseHas('security_activity_logs', [
            'site_id' => $this->site->id,
            'event_type' => 'failed_login',
            'username' => 'admin',
            'ip_address' => '192.168.1.100',
        ]);
    }

    #[Test]
    public function activity_logs_validates_input(): void
    {
        $data = ['logs' => 'not-an-array'];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/activity-logs",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertStatus(422);
    }

    // ── Sync State ──

    #[Test]
    public function sync_state_accepts_valid_settings(): void
    {
        $data = [
            'settings' => [
                [
                    'category' => 'hardening',
                    'key' => 'disable_theme_editor',
                    'applied' => true,
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/agent/{$this->apiKey}/security/sync-state",
            $data,
            $this->signedHeaders(json_encode($data))
        );

        $response->assertOk()
            ->assertJsonPath('synced', true);
    }
}
