<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2;

use App\Backup\V2\Enums\RestoreSessionState;
use PHPUnit\Framework\TestCase;

class RestoreSessionStateTest extends TestCase
{
    public function test_string_values_are_stable(): void
    {
        $expected = [
            'Requested' => 'requested',
            'ValidatingBackup' => 'validating_backup',
            'PreRestoreBackup' => 'pre_restore_backup',
            'Downloading' => 'downloading',
            'Decrypting' => 'decrypting',
            'Verifying' => 'verifying',
            'Maintenance' => 'maintenance',
            'DatabaseRestore' => 'database_restore',
            'FileRestore' => 'file_restore',
            'Cleanup' => 'cleanup',
            'PostRestoreValidation' => 'post_restore_validation',
            'Completed' => 'completed',
            'Rollback' => 'rollback',
            'Failed' => 'failed',
        ];

        foreach ($expected as $case => $value) {
            $this->assertSame($value, constant(RestoreSessionState::class.'::'.$case)->value);
        }

        $this->assertCount(count($expected), RestoreSessionState::cases());
    }

    public function test_terminal_states(): void
    {
        $terminal = [RestoreSessionState::Completed, RestoreSessionState::Failed];

        foreach (RestoreSessionState::cases() as $state) {
            $this->assertSame(in_array($state, $terminal, true), $state->isTerminal(), $state->value);
            $this->assertSame(! in_array($state, $terminal, true), $state->isActive(), $state->value);
        }
    }

    public function test_rollback_is_active_but_not_terminal(): void
    {
        $this->assertTrue(RestoreSessionState::Rollback->isActive());
        $this->assertFalse(RestoreSessionState::Rollback->isTerminal());
    }
}
