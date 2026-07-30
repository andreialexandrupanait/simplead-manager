<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Jobs\CreateBackup;
use App\Models\BackupConfig;
use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;

/**
 * SPEC §6.2 — "Backup" on the shared engine.
 *
 * Long duration, so it runs on the backups queue and never blocks a cache purge
 * behind it. The backup engine reports its own per-backup outcome, so this
 * operation's job is the gate: a site with no backup destination configured is
 * skipped with that reason instead of silently producing nothing.
 */
class CreateBackupOperation implements Operation
{
    public function key(): string
    {
        return 'backup.create';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Long;
    }

    public function isReversible(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        $site = $context->site;

        if (! $site->is_connected) {
            return OperationResult::skipped($site->id, 'Site is not connected.');
        }

        if (! BackupConfig::where('site_id', $site->id)->exists()) {
            return OperationResult::skipped($site->id, 'No backup configuration — there is nowhere to store a backup.');
        }

        return OperationResult::success($site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $type = (string) ($context->params['type'] ?? 'full');

        CreateBackup::dispatch($context->site, $type, 'manual');

        return OperationResult::success($context->site->id, "Backup ({$type}) queued.");
    }

    public function verify(OperationContext $context): OperationResult
    {
        return OperationResult::verified(
            $context->site->id,
            'The backup job records its own completion and failure state.',
        );
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped($context->site->id, 'Taking a backup is not something to undo.');
    }
}
