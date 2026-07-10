<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * On-demand restore verification of a single backup (operator "Test restore"
 * action). Runs the same restorability checks the weekly Level-B sweep does.
 */
class RunBackupVerification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public Backup $backup)
    {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return 'verify-backup-'.$this->backup->id;
    }

    public function handle(BackupVerifier $verifier): void
    {
        $verifier->verify($this->backup);
    }
}
