<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Exceptions\IllegalStateTransition;
use App\Backup\V2\StateMachine\BackupStateMachine;
use PHPUnit\Framework\TestCase;

class BackupStateMachineTest extends TestCase
{
    public function test_happy_path_forward_edges_are_legal(): void
    {
        $path = [
            S::Requested, S::CapabilityCheck, S::Inventory, S::DatabaseExport,
            S::FileDiff, S::Chunking, S::UploadInitializing, S::Uploading,
            S::UploadVerifying, S::Finalizing, S::Completed,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                BackupStateMachine::canTransition($path[$i], $path[$i + 1]),
                "{$path[$i]->value} → {$path[$i + 1]->value} should be legal"
            );
        }
    }

    public function test_every_declared_edge_is_permitted_and_asserts_cleanly(): void
    {
        foreach (S::cases() as $from) {
            foreach (BackupStateMachine::transitionsFrom($from) as $to) {
                $this->assertTrue(BackupStateMachine::canTransition($from, $to));
                BackupStateMachine::assertTransition($from, $to); // must not throw
            }
        }
    }

    public function test_processing_states_can_branch_to_side_states(): void
    {
        foreach (S::processing() as $from) {
            foreach ([S::RetryWait, S::Paused, S::Cancelling, S::Failed] as $side) {
                $this->assertTrue(
                    BackupStateMachine::canTransition($from, $side),
                    "{$from->value} → {$side->value} should be legal"
                );
            }
        }
    }

    public function test_corrupt_only_reachable_from_verification_states(): void
    {
        $this->assertTrue(BackupStateMachine::canTransition(S::UploadVerifying, S::Corrupt));
        $this->assertTrue(BackupStateMachine::canTransition(S::Finalizing, S::Corrupt));
        $this->assertFalse(BackupStateMachine::canTransition(S::Inventory, S::Corrupt));
        $this->assertFalse(BackupStateMachine::canTransition(S::Requested, S::Corrupt));
    }

    public function test_retry_wait_resumes_into_processing_states(): void
    {
        foreach (S::processing() as $to) {
            $this->assertTrue(BackupStateMachine::canTransition(S::RetryWait, $to));
            $this->assertTrue(BackupStateMachine::canTransition(S::Paused, $to));
        }
    }

    public function test_cancelling_resolves_to_cancelled(): void
    {
        $this->assertTrue(BackupStateMachine::canTransition(S::Cancelling, S::Cancelled));
        $this->assertTrue(BackupStateMachine::canTransition(S::Cancelling, S::Failed));
    }

    public static function illegalEdges(): array
    {
        return [
            'skip inventory' => [S::Requested, S::Inventory],
            'backwards' => [S::Uploading, S::Inventory],
            'jump to completed' => [S::Inventory, S::Completed],
            'terminal completed out' => [S::Completed, S::Failed],
            'terminal failed out' => [S::Failed, S::Requested],
            'terminal cancelled out' => [S::Cancelled, S::Requested],
            'terminal corrupt out' => [S::Corrupt, S::Requested],
            'requested to corrupt' => [S::Requested, S::Corrupt],
        ];
    }

    /**
     * @dataProvider illegalEdges
     */
    public function test_illegal_edges_are_rejected(S $from, S $to): void
    {
        $this->assertFalse(BackupStateMachine::canTransition($from, $to));

        $this->expectException(IllegalStateTransition::class);
        BackupStateMachine::assertTransition($from, $to);
    }

    public function test_same_state_transition_is_idempotent_no_op(): void
    {
        foreach (S::cases() as $state) {
            $this->assertTrue(
                BackupStateMachine::canTransition($state, $state),
                "self-transition on {$state->value} must be a permitted no-op"
            );
            BackupStateMachine::assertTransition($state, $state); // must not throw
        }
    }

    public function test_terminal_states_have_no_outgoing_edges(): void
    {
        foreach ([S::Completed, S::Cancelled, S::Failed, S::Corrupt] as $terminal) {
            $this->assertSame([], BackupStateMachine::transitionsFrom($terminal));
        }
    }
}
