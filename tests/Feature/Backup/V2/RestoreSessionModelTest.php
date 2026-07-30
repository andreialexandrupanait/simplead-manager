<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Exceptions\IllegalStateTransition;
use App\Backup\V2\Models\RestoreSession;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreSessionModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(array $overrides = []): RestoreSession
    {
        $site = Site::factory()->create();

        return RestoreSession::create(array_merge([
            'site_id' => $site->id,
            'mode' => 'safe_merge',
            'scope' => ['database' => true, 'files' => ['wp-content/uploads']],
            'state' => RestoreSessionState::Requested,
            'idempotency_key' => 'restore-'.uniqid(),
        ], $overrides));
    }

    public function test_jsonb_and_enum_casts_round_trip(): void
    {
        $session = $this->makeSession([
            'cursor' => ['batch' => 2],
            'checkpoint' => ['stage' => 'file_restore'],
            'rollback_point' => ['snapshot' => 'pre-restore-1'],
        ]);

        $fresh = RestoreSession::findOrFail($session->id);

        $this->assertInstanceOf(RestoreSessionState::class, $fresh->state);
        // jsonb does not preserve object key order — compare by value (assertEquals).
        $this->assertEquals(['database' => true, 'files' => ['wp-content/uploads']], $fresh->scope);
        $this->assertEquals(['batch' => 2], $fresh->cursor);
        $this->assertEquals(['stage' => 'file_restore'], $fresh->checkpoint);
        $this->assertEquals(['snapshot' => 'pre-restore-1'], $fresh->rollback_point);
    }

    public function test_idempotency_key_is_unique(): void
    {
        $key = 'restore-dup-1';
        $this->makeSession(['idempotency_key' => $key]);

        $this->expectException(QueryException::class);
        $this->makeSession(['idempotency_key' => $key]);
    }

    public function test_transition_to_updates_state_and_stage(): void
    {
        $session = $this->makeSession();

        $session->transitionTo(RestoreSessionState::ValidatingBackup, 'validating chain');

        $this->assertSame(RestoreSessionState::ValidatingBackup, $session->state);
        $this->assertSame('validating chain', $session->stage);
        $this->assertNotNull($session->started_at);
    }

    public function test_rollback_path_is_legal_from_mutating_state(): void
    {
        $session = $this->makeSession(['state' => RestoreSessionState::FileRestore]);

        $session->transitionTo(RestoreSessionState::Rollback, 'rolling back');
        $this->assertSame(RestoreSessionState::Rollback, $session->state);

        $session->transitionTo(RestoreSessionState::Failed);
        $this->assertSame(RestoreSessionState::Failed, $session->state);
        $this->assertNotNull($session->completed_at);
    }

    public function test_transition_to_rejects_illegal_edge(): void
    {
        $session = $this->makeSession();

        $this->expectException(IllegalStateTransition::class);
        $session->transitionTo(RestoreSessionState::Completed);
    }
}
