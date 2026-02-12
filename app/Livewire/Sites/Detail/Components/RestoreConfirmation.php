<?php

namespace App\Livewire\Sites\Detail\Components;

use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Livewire\Attributes\On;
use Livewire\Component;

class RestoreConfirmation extends Component
{
    public Site $site;
    public ?Backup $backup = null;
    public bool $confirmed = false;
    public bool $backupBeforeRestore = true;
    public ?int $preRestoreBackupId = null;
    public ?string $preRestoreStatus = null;

    #[On('open-restore-confirmation')]
    public function openModal(int $backupId): void
    {
        $this->backup = Backup::with(['site', 'storageDestination'])->findOrFail($backupId);
        $this->confirmed = false;
        $this->backupBeforeRestore = true;
        $this->preRestoreBackupId = null;
        $this->preRestoreStatus = null;
        $this->dispatch('open-modal-restore-confirmation');
    }

    public function restore(): void
    {
        if (!$this->confirmed || !$this->backup) {
            return;
        }

        if ($this->backupBeforeRestore && !$this->preRestoreBackupId) {
            $this->startPreRestoreBackup();
            return;
        }

        // If pre-restore backup is still running, don't proceed
        if ($this->preRestoreBackupId && $this->preRestoreStatus && !in_array($this->preRestoreStatus, ['completed', 'failed'])) {
            return;
        }

        $this->dispatchRestore();
    }

    public function restoreAnyway(): void
    {
        if (!$this->confirmed || !$this->backup) {
            return;
        }

        $this->dispatchRestore();
    }

    public function checkPreRestoreStatus(): void
    {
        if (!$this->preRestoreBackupId) {
            return;
        }

        $preBackup = Backup::find($this->preRestoreBackupId);
        if (!$preBackup) {
            return;
        }

        $this->preRestoreStatus = $preBackup->status;

        if ($preBackup->status === 'completed') {
            // Auto-dispatch restore now
            $this->dispatchRestore();
        }
    }

    protected function startPreRestoreBackup(): void
    {
        $destination = $this->resolveDestination();
        if (!$destination) {
            session()->flash('backup-error', 'No storage destination configured.');
            $this->dispatch('close-modal-restore-confirmation');
            return;
        }

        $preBackup = Backup::create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
            'type' => 'database',
            'trigger' => 'pre_restore',
            'status' => 'pending',
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Creating safety backup before restore...',
            'includes_database' => true,
            'includes_files' => false,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'is_locked' => true,
            'lock_reason' => 'pre-restore',
            'started_at' => now(),
        ]);

        CreateBackup::dispatch($this->site, 'database', 'pre_restore', $destination->id, $preBackup->id);

        $this->preRestoreBackupId = $preBackup->id;
        $this->preRestoreStatus = 'pending';
    }

    protected function dispatchRestore(): void
    {
        if (!$this->backup) {
            return;
        }

        $this->backup->update([
            'restore_status' => 'pending',
            'restore_stage' => 'queued',
            'restore_progress_percent' => 0,
            'restore_progress_message' => 'Restore queued, waiting to start...',
            'restore_error_message' => null,
        ]);

        RestoreBackup::dispatch($this->backup);

        $this->dispatch('restore-dispatched', backupId: $this->backup->id);
        $this->dispatch('close-modal-restore-confirmation');
        session()->flash('backup-success', 'Restore has been queued. The site will be restored from this backup.');
    }

    protected function resolveDestination(): ?StorageDestination
    {
        return StorageDestination::resolveForSite($this->site);
    }

    public function render()
    {
        return view('livewire.sites.detail.components.restore-confirmation');
    }
}
