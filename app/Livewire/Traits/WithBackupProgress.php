<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\Backup;
use App\Services\JobTracker;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

trait WithBackupProgress
{
    public ?int $trackingBackupId = null;

    public ?int $trackingRestoreBackupId = null;

    #[Computed]
    public function activeBackup(): ?Backup
    {
        if (! $this->trackingBackupId) {
            return null;
        }

        return Backup::find($this->trackingBackupId);
    }

    #[Computed]
    public function activeRestore(): ?Backup
    {
        if (! $this->trackingRestoreBackupId) {
            return null;
        }

        return Backup::find($this->trackingRestoreBackupId);
    }

    #[Computed]
    public function progressLog(): array
    {
        if (! $this->trackingBackupId) {
            return [];
        }

        return JobTracker::getLog('backup-'.$this->site->id);
    }

    #[Computed]
    public function restoreProgressLog(): array
    {
        if (! $this->trackingRestoreBackupId) {
            return [];
        }

        return JobTracker::getLog('restore-'.$this->trackingRestoreBackupId);
    }

    public function pollProgress(): void
    {
        if ($this->trackingBackupId) {
            unset($this->activeBackup);
            unset($this->progressLog);

            $ab = $this->activeBackup;
            if ($ab && in_array($ab->status->value ?? $ab->status, ['completed', 'failed'])) {
                // Let the auto-dismiss timer in Alpine handle cleanup
            }
        }

        if ($this->trackingRestoreBackupId) {
            unset($this->activeRestore);
            unset($this->restoreProgressLog);

            $ar = $this->activeRestore;
            if ($ar && in_array($ar->restore_status, ['completed', 'failed'])) {
                // Let the auto-dismiss timer in Alpine handle cleanup
            }
        }
    }

    public function refreshProgress(): void
    {
        unset($this->activeBackup);
    }

    public function refreshRestoreProgress(): void
    {
        unset($this->activeRestore);
    }

    public function dismissProgress(): void
    {
        $this->trackingBackupId = null;
        unset($this->activeBackup);
        unset($this->progressLog);
    }

    public function dismissRestoreProgress(): void
    {
        $this->trackingRestoreBackupId = null;
        unset($this->activeRestore);
        unset($this->restoreProgressLog);
    }

    #[On('restore-dispatched')]
    public function onRestoreDispatched(int $backupId): void
    {
        $this->trackingRestoreBackupId = $backupId;
        unset($this->activeRestore);
    }
}
