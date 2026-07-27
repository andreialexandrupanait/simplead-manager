<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Exceptions\IllegalStateTransition;
use App\Backup\V2\Models\BackupSession;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupSessionModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(array $overrides = []): BackupSession
    {
        $site = Site::factory()->create();

        return BackupSession::create(array_merge([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['paths' => ['wp-content'], 'tables' => ['wp_posts']],
            'exclusions' => ['*.log', 'cache/*'],
            'exclusion_policy_hash' => 'excl-hash-1',
            'scope_hash' => 'scope-hash-1',
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'idempotency_key' => 'idem-'.uniqid(),
            'format_version' => 'simplead-backup/1',
        ], $overrides));
    }

    public function test_jsonb_and_enum_casts_round_trip(): void
    {
        $session = $this->makeSession([
            'cursor' => ['file_index' => 12, 'offset' => 4096],
            'checkpoint' => ['stage' => 'uploading', 'part' => 3],
        ]);

        $fresh = BackupSession::findOrFail($session->id);

        $this->assertInstanceOf(BackupSessionState::class, $fresh->state);
        $this->assertSame(BackupSessionState::Requested, $fresh->state);
        // jsonb does not preserve object key order — compare by value (assertEquals).
        $this->assertEquals(['paths' => ['wp-content'], 'tables' => ['wp_posts']], $fresh->scope);
        $this->assertSame(['*.log', 'cache/*'], $fresh->exclusions);
        $this->assertEquals(['file_index' => 12, 'offset' => 4096], $fresh->cursor);
        $this->assertEquals(['stage' => 'uploading', 'part' => 3], $fresh->checkpoint);
        $this->assertSame(0, $fresh->attempt);
        $this->assertSame(0, $fresh->retry_count);
    }

    public function test_idempotency_key_is_unique(): void
    {
        $key = 'dup-key-123';
        $this->makeSession(['idempotency_key' => $key]);

        $this->expectException(QueryException::class);
        $this->makeSession(['idempotency_key' => $key]);
    }

    public function test_transition_to_updates_state_and_stage(): void
    {
        $session = $this->makeSession();

        $session->transitionTo(BackupSessionState::CapabilityCheck, 'checking host capabilities');

        $this->assertSame(BackupSessionState::CapabilityCheck, $session->state);
        $this->assertSame('checking host capabilities', $session->stage);
        $this->assertNotNull($session->started_at);

        $fresh = BackupSession::findOrFail($session->id);
        $this->assertSame(BackupSessionState::CapabilityCheck, $fresh->state);
        $this->assertSame('checking host capabilities', $fresh->stage);
    }

    public function test_transition_to_terminal_sets_completed_at(): void
    {
        $session = $this->makeSession(['state' => BackupSessionState::Finalizing]);

        $session->transitionTo(BackupSessionState::Completed);

        $this->assertSame(BackupSessionState::Completed, $session->state);
        $this->assertNotNull($session->completed_at);
    }

    public function test_transition_to_rejects_illegal_edge(): void
    {
        $session = $this->makeSession();

        $this->expectException(IllegalStateTransition::class);
        $session->transitionTo(BackupSessionState::Completed);
    }

    public function test_transition_to_same_state_is_noop(): void
    {
        $session = $this->makeSession(['state' => BackupSessionState::Inventory]);

        $session->transitionTo(BackupSessionState::Inventory, 'still inventorying');

        $this->assertSame(BackupSessionState::Inventory, $session->state);
        $this->assertSame('still inventorying', $session->stage);
    }

    public function test_heartbeat_and_record_error(): void
    {
        $session = $this->makeSession();

        $session->heartbeat();
        $this->assertNotNull($session->heartbeat_at);

        $session->recordError(BackupErrorCode::UploadFailed, 'part 3 failed after 5 retries');
        $fresh = BackupSession::findOrFail($session->id);
        $this->assertSame(BackupErrorCode::UploadFailed->value, $fresh->error_code);
        $this->assertSame('part 3 failed after 5 retries', $fresh->error_message);
    }

    public function test_full_base_self_relation(): void
    {
        $base = $this->makeSession();
        $increment = $this->makeSession([
            'type' => 'incremental',
            'full_base_id' => $base->id,
            'chain_position' => 1,
        ]);

        $this->assertTrue($increment->fullBase->is($base));
        $this->assertTrue($base->chainMembers->first()->is($increment));
    }
}
