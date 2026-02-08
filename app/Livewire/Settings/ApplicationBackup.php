<?php

namespace App\Livewire\Settings;

use App\Jobs\CreateAppBackup;
use App\Models\AppBackup;
use App\Models\AppBackupConfig;
use App\Models\StorageDestination;
use App\Services\AppBackup\AppBackupService;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class ApplicationBackup extends Component
{
    use WithPagination;

    // Config form
    public bool $isEnabled = false;
    public string $frequency = 'daily';
    public string $time = '02:00';
    public ?int $dayOfWeek = null;
    public ?int $dayOfMonth = null;
    public string $timezone = 'Europe/Bucharest';
    public string $type = 'full';
    public array $components = ['database', 'env', 'storage'];
    public ?int $storageDestinationId = null;
    public string $retentionType = 'count';
    public int $retentionValue = 7;
    public bool $encryptBackup = false;
    public string $encryptionPassword = '';

    // Filter
    public string $statusFilter = 'all';

    // Create modal
    public bool $showCreateModal = false;
    public string $createType = 'full';
    public bool $createIncludeLogs = false;
    public bool $createIncludeCodebase = false;

    // Restore modal
    #[Locked]
    public ?int $restoreBackupId = null;
    public bool $restoreConfirmed = false;

    // Env viewer
    public string $envContent = '';

    // Log viewer
    public array $logEntries = [];

    // Tracking
    #[Locked]
    public ?int $trackingBackupId = null;
    public bool $awaitingBackup = false;

    public function mount(): void
    {
        $config = AppBackupConfig::instance();

        $this->isEnabled = $config->is_enabled;
        $this->frequency = $config->frequency;
        $this->time = $config->time;
        $this->dayOfWeek = $config->day_of_week;
        $this->dayOfMonth = $config->day_of_month;
        $this->timezone = $config->timezone;
        $this->type = $config->type;
        $this->components = $config->components ?? ['database', 'env', 'storage'];
        $this->storageDestinationId = $config->storage_destination_id;
        $this->retentionType = $config->retention_type;
        $this->retentionValue = $config->retention_value;
        $this->encryptBackup = $config->encrypt_backup;

        // Track active backup
        $active = AppBackup::whereIn('status', ['pending', 'in_progress'])->latest()->first();
        if ($active) {
            $this->trackingBackupId = $active->id;
        }
    }

    #[Computed]
    public function activeBackup(): ?AppBackup
    {
        if (!$this->trackingBackupId) return null;
        return AppBackup::find($this->trackingBackupId);
    }

    #[Computed]
    public function backupLogEntries(): array
    {
        return $this->activeBackup?->log ?? [];
    }

    #[Computed]
    public function storageDestinations()
    {
        return StorageDestination::where('is_active', true)->get();
    }

    #[Computed]
    public function totalStorageUsed(): string
    {
        $total = AppBackup::where('status', 'completed')->sum('file_size');
        return $this->formatBytes($total);
    }

    #[Computed]
    public function lastBackup(): ?AppBackup
    {
        return AppBackup::where('status', 'completed')->latest()->first();
    }

    #[Computed]
    public function config(): AppBackupConfig
    {
        return AppBackupConfig::instance();
    }

    public function saveConfig(): void
    {
        $this->validate([
            'frequency' => 'required|in:daily,weekly,monthly',
            'time' => 'required|date_format:H:i',
            'dayOfWeek' => 'nullable|integer|between:0,6',
            'dayOfMonth' => 'nullable|integer|between:1,28',
            'timezone' => 'required|timezone',
            'type' => 'required|in:full,database,config,storage',
            'retentionType' => 'required|in:count,days',
            'retentionValue' => 'required|integer|min:1|max:365',
            'encryptionPassword' => $this->encryptBackup ? 'required|string|min:8' : 'nullable',
        ]);

        $config = AppBackupConfig::instance();

        $data = [
            'is_enabled' => $this->isEnabled,
            'frequency' => $this->frequency,
            'time' => $this->time,
            'day_of_week' => $this->frequency === 'weekly' ? $this->dayOfWeek : null,
            'day_of_month' => $this->frequency === 'monthly' ? $this->dayOfMonth : null,
            'timezone' => $this->timezone,
            'type' => $this->type,
            'components' => $this->components,
            'storage_destination_id' => $this->storageDestinationId,
            'retention_type' => $this->retentionType,
            'retention_value' => $this->retentionValue,
            'encrypt_backup' => $this->encryptBackup,
        ];

        if ($this->encryptBackup && $this->encryptionPassword) {
            $data['encryption_password'] = $this->encryptionPassword;
        }

        if (!$this->encryptBackup) {
            $data['encryption_password'] = null;
        }

        $config->update($data);

        // Calculate next backup time if enabled
        if ($this->isEnabled) {
            $config->update(['next_backup_at' => $config->calculateNextBackupAt()]);
        } else {
            $config->update(['next_backup_at' => null]);
        }

        $this->encryptionPassword = '';
        unset($this->config);

        $this->dispatch('notify', type: 'success', message: 'Backup configuration saved.');
    }

    public function openCreateModal(): void
    {
        $this->createType = 'full';
        $this->createIncludeLogs = false;
        $this->createIncludeCodebase = false;
        $this->showCreateModal = true;
        $this->dispatch('open-modal-create-backup');
    }

    public function createBackup(): void
    {
        $options = [];
        if ($this->createIncludeLogs) $options['include_logs'] = true;
        if ($this->createIncludeCodebase) $options['include_codebase'] = true;

        $config = AppBackupConfig::instance();

        CreateAppBackup::dispatch(
            $this->createType,
            'manual',
            $config->storage_destination_id,
            $options,
        );

        $this->showCreateModal = false;

        $this->dispatch('notify', type: 'success', message: 'Backup job queued. It will start shortly.');

        // Set awaiting flag — polling will pick up the record once the job creates it
        $this->awaitingBackup = true;
        $this->trackingBackupId = null;
        unset($this->activeBackup);
    }

    public function downloadBackup(int $id): mixed
    {
        $backup = AppBackup::findOrFail($id);

        if (!$backup->storage_path) {
            $this->dispatch('notify', type: 'error', message: 'Backup file not available for download.');
            return null;
        }

        $destination = $backup->storageDestination;

        // Local fallback (no destination)
        if (!$destination) {
            $url = URL::signedRoute('app-backups.download', ['appBackup' => $backup->id]);
            return $this->redirect($url);
        }

        if ($destination->type === 'local') {
            $url = URL::signedRoute('app-backups.download', ['appBackup' => $backup->id]);
            return $this->redirect($url);
        }

        // Remote: get temporary URL
        $driver = StorageFactory::make($destination);
        $url = $driver->temporaryUrl($backup->storage_path);

        if (!$url) {
            $this->dispatch('notify', type: 'error', message: 'Could not generate download link.');
            return null;
        }

        return $this->redirect($url);
    }

    public function openRestoreModal(int $id): void
    {
        $this->restoreBackupId = $id;
        $this->restoreConfirmed = false;
        $this->dispatch('open-modal-restore-confirm');
    }

    // Restore verification
    public array $restoreVerification = [];

    public function restoreDatabase(): void
    {
        if (!$this->restoreConfirmed) {
            $this->dispatch('notify', type: 'error', message: 'Please confirm you understand the risks.');
            return;
        }

        $backup = AppBackup::findOrFail($this->restoreBackupId);

        try {
            $service = app(AppBackupService::class);
            $verification = $service->restoreDatabase($backup);

            $this->restoreBackupId = null;
            $this->restoreConfirmed = false;
            $this->restoreVerification = $verification;

            if ($verification['status'] === 'ok') {
                $msg = "Database restored successfully. All {$verification['tables_matched']} tables verified.";
                $this->dispatch('notify', type: 'success', message: $msg);
            } elseif ($verification['status'] === 'warning') {
                $msg = "Database restored. {$verification['tables_matched']} tables match, {$verification['tables_different']} differ.";
                $this->dispatch('notify', type: 'warning', message: $msg);
            } else {
                $this->dispatch('notify', type: 'success', message: 'Database restored successfully.');
            }

            $this->dispatch('open-modal-restore-verification');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Restore failed: ' . $e->getMessage());
        }
    }

    public function viewEnv(int $id): void
    {
        $backup = AppBackup::findOrFail($id);

        try {
            $service = app(AppBackupService::class);
            $this->envContent = $service->viewEnv($backup);
            $this->dispatch('open-modal-view-env');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Could not read .env: ' . $e->getMessage());
        }
    }

    public function viewLog(int $id): void
    {
        $backup = AppBackup::findOrFail($id);
        $this->logEntries = $backup->log ?? [];
        $this->dispatch('open-modal-view-log');
    }

    public function toggleLock(int $id): void
    {
        $backup = AppBackup::findOrFail($id);
        $backup->update([
            'is_locked' => !$backup->is_locked,
            'lock_reason' => !$backup->is_locked ? 'manual' : null,
        ]);
    }

    public function deleteBackup(int $id): void
    {
        $backup = AppBackup::findOrFail($id);

        if ($backup->is_locked) {
            $this->dispatch('notify', type: 'error', message: 'Cannot delete a locked backup. Unlock it first.');
            return;
        }

        try {
            $service = app(AppBackupService::class);
            $service->deleteBackup($backup);
            unset($this->totalStorageUsed, $this->lastBackup);
            $this->dispatch('notify', type: 'success', message: 'Backup deleted.');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Delete failed: ' . $e->getMessage());
        }
    }

    public function refreshProgress(): void
    {
        unset($this->activeBackup, $this->backupLogEntries);

        $active = AppBackup::whereIn('status', ['pending', 'in_progress'])->latest()->first();
        if ($active) {
            $this->trackingBackupId = $active->id;
            $this->awaitingBackup = false;
        } elseif (!$this->awaitingBackup) {
            // Backup finished (completed/failed) — stop tracking
            $this->trackingBackupId = null;
            unset($this->lastBackup, $this->totalStorageUsed);
        }
        // If awaitingBackup is true but no record found yet, keep polling
    }

    public function dismissProgress(): void
    {
        $this->trackingBackupId = null;
        $this->awaitingBackup = false;
        unset($this->activeBackup, $this->backupLogEntries);
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    public function render()
    {
        $query = AppBackup::with('storageDestination')
            ->orderByDesc('created_at');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.settings.application-backup', [
            'backups' => $query->paginate(15),
        ])
            ->layout('components.layouts.app', ['title' => 'Application Backup']);
    }
}
