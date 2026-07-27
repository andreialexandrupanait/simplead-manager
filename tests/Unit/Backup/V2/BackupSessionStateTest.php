<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use PHPUnit\Framework\TestCase;

class BackupSessionStateTest extends TestCase
{
    public function test_string_values_are_stable(): void
    {
        $expected = [
            'Requested' => 'requested',
            'CapabilityCheck' => 'capability_check',
            'Inventory' => 'inventory',
            'DatabaseExport' => 'database_export',
            'FileDiff' => 'file_diff',
            'Chunking' => 'chunking',
            'UploadInitializing' => 'upload_initializing',
            'Uploading' => 'uploading',
            'UploadVerifying' => 'upload_verifying',
            'Finalizing' => 'finalizing',
            'Completed' => 'completed',
            'RetryWait' => 'retry_wait',
            'Paused' => 'paused',
            'Cancelling' => 'cancelling',
            'Cancelled' => 'cancelled',
            'Failed' => 'failed',
            'Corrupt' => 'corrupt',
        ];

        foreach ($expected as $case => $value) {
            $this->assertSame($value, constant(BackupSessionState::class.'::'.$case)->value);
        }

        // Guard against silent additions/removals.
        $this->assertCount(count($expected), BackupSessionState::cases());
    }

    public function test_terminal_states(): void
    {
        $terminal = [
            BackupSessionState::Completed,
            BackupSessionState::Cancelled,
            BackupSessionState::Failed,
            BackupSessionState::Corrupt,
        ];

        foreach (BackupSessionState::cases() as $state) {
            $this->assertSame(in_array($state, $terminal, true), $state->isTerminal(), $state->value);
            $this->assertSame(! in_array($state, $terminal, true), $state->isActive(), $state->value);
        }
    }

    public function test_processing_states_are_active_and_non_terminal(): void
    {
        foreach (BackupSessionState::processing() as $state) {
            $this->assertTrue($state->isActive());
            $this->assertFalse($state->isTerminal());
        }
    }
}
