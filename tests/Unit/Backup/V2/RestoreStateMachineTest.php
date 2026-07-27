<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2;

use App\Backup\V2\Enums\RestoreSessionState as S;
use App\Backup\V2\Exceptions\IllegalStateTransition;
use App\Backup\V2\StateMachine\RestoreStateMachine;
use PHPUnit\Framework\TestCase;

class RestoreStateMachineTest extends TestCase
{
    public function test_happy_path_forward_edges_are_legal(): void
    {
        $path = [
            S::Requested, S::ValidatingBackup, S::PreRestoreBackup, S::Downloading,
            S::Decrypting, S::Verifying, S::Maintenance, S::DatabaseRestore,
            S::FileRestore, S::Cleanup, S::PostRestoreValidation, S::Completed,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                RestoreStateMachine::canTransition($path[$i], $path[$i + 1]),
                "{$path[$i]->value} → {$path[$i + 1]->value} should be legal"
            );
        }
    }

    public function test_every_declared_edge_is_permitted_and_asserts_cleanly(): void
    {
        foreach (S::cases() as $from) {
            foreach (RestoreStateMachine::transitionsFrom($from) as $to) {
                $this->assertTrue(RestoreStateMachine::canTransition($from, $to));
                RestoreStateMachine::assertTransition($from, $to);
            }
        }
    }

    public function test_rollback_reachable_only_from_mutating_states(): void
    {
        foreach (S::mutating() as $from) {
            $this->assertTrue(
                RestoreStateMachine::canTransition($from, S::Rollback),
                "{$from->value} → rollback should be legal"
            );
        }

        // Non-mutating states cannot roll back.
        $this->assertFalse(RestoreStateMachine::canTransition(S::Downloading, S::Rollback));
        $this->assertFalse(RestoreStateMachine::canTransition(S::Verifying, S::Rollback));
        $this->assertFalse(RestoreStateMachine::canTransition(S::Requested, S::Rollback));
    }

    public function test_rollback_resolves_to_failed_only(): void
    {
        $this->assertSame([S::Failed], RestoreStateMachine::transitionsFrom(S::Rollback));
        $this->assertFalse(RestoreStateMachine::canTransition(S::Rollback, S::Completed));
    }

    public function test_every_processing_state_can_fail(): void
    {
        foreach (S::processing() as $from) {
            $this->assertTrue(RestoreStateMachine::canTransition($from, S::Failed));
        }
    }

    public static function illegalEdges(): array
    {
        return [
            'skip validation' => [S::Requested, S::Downloading],
            'backwards' => [S::FileRestore, S::Downloading],
            'jump to completed' => [S::Downloading, S::Completed],
            'terminal completed out' => [S::Completed, S::Failed],
            'terminal failed out' => [S::Failed, S::Requested],
        ];
    }

    /**
     * @dataProvider illegalEdges
     */
    public function test_illegal_edges_are_rejected(S $from, S $to): void
    {
        $this->assertFalse(RestoreStateMachine::canTransition($from, $to));

        $this->expectException(IllegalStateTransition::class);
        RestoreStateMachine::assertTransition($from, $to);
    }

    public function test_same_state_transition_is_idempotent_no_op(): void
    {
        foreach (S::cases() as $state) {
            $this->assertTrue(RestoreStateMachine::canTransition($state, $state));
            RestoreStateMachine::assertTransition($state, $state);
        }
    }

    public function test_terminal_states_have_no_outgoing_edges(): void
    {
        foreach ([S::Completed, S::Failed] as $terminal) {
            $this->assertSame([], RestoreStateMachine::transitionsFrom($terminal));
        }
    }
}
