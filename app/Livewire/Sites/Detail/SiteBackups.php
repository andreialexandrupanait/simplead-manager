<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class SiteBackups extends Component
{
    use WithPagination, WithSiteAuthorization;

    public Site $site;

    public ?int $trackingBackupId = null;

    public ?int $trackingRestoreBackupId = null;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        // If there's an in-progress backup on page load, track it
        $active = $this->site->backups()
            ->whereIn('status', ['pending', 'in_progress'])
            ->latest()
            ->first();
        if ($active) {
            $this->trackingBackupId = $active->id;
        }

        // If there's an in-progress restore on page load, track it
        $activeRestore = $this->site->backups()
            ->whereIn('restore_status', ['pending', 'in_progress'])
            ->latest()
            ->first();
        if ($activeRestore) {
            $this->trackingRestoreBackupId = $activeRestore->id;
        }
    }

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
    public function backupConfig()
    {
        return $this->site->backupConfig;
    }

    #[Computed]
    public function storageDestinations()
    {
        return StorageDestination::where('is_active', true)->get();
    }

    #[Computed]
    public function storageUsage()
    {
        $totalSize = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->sum('file_size');

        return $this->formatBytes($totalSize);
    }

    #[Computed]
    public function estimatedBackupSize(): string
    {
        $dbMb = (float) ($this->site->db_size_mb ?? 0);
        $uploadsMb = (float) ($this->site->uploads_size_mb ?? 0);
        $totalMb = ($dbMb + $uploadsMb) * 0.6; // ~60% compression factor

        if ($totalMb < 1) {
            return '< 1 MB';
        }

        return round($totalMb, 1).' MB';
    }

    #[Computed]
    public function storageQuotaInfo(): ?array
    {
        $config = $this->site->backupConfig;
        if (! $config?->storage_destination_id) {
            return null;
        }

        $destination = StorageDestination::find($config->storage_destination_id);
        if (! $destination || ! $destination->quota_bytes) {
            return null;
        }

        $percent = $destination->usage_percent;
        if ($percent === null) {
            return null;
        }

        $level = 'ok';
        if ($percent >= 90) {
            $level = 'error';
        } elseif ($percent >= 75) {
            $level = 'warning';
        }

        return [
            'percent' => $percent,
            'used' => $destination->used_formatted,
            'total' => $this->formatBytes($destination->quota_bytes),
            'level' => $level,
        ];
    }

    public function getBackupHistoryProperty()
    {
        return $this->site->backups()
            ->with(['storageDestination', 'parentBackup'])
            ->selectSub(
                Backup::selectRaw('file_size')
                    ->whereColumn('site_id', 'backups.site_id')
                    ->where('status', 'completed')
                    ->whereColumn('id', '<', 'backups.id')
                    ->whereNotNull('file_size')
                    ->orderByDesc('id')
                    ->limit(1),
                '_previous_file_size'
            )
            ->select('backups.*')
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    public function backupDatabase(): void
    {
        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('backup-error', 'Too many backup requests. Please wait before trying again.');

            return;
        }

        $destination = $this->resolveDestination();
        if (! $destination) {
            session()->flash('backup-error', 'No storage destination configured. Please configure a storage destination in Settings first.');

            return;
        }

        $backup = Backup::create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
            'type' => 'database',
            'trigger' => 'manual',
            'status' => 'pending',
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Backup queued, waiting to start...',
            'includes_database' => true,
            'includes_files' => false,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'plugins_count' => $this->site->sitePlugins()->count(),
            'themes_count' => $this->site->siteThemes()->count(),
            'db_size_mb' => $this->site->db_size_mb,
            'started_at' => now(),
        ]);

        try {
            CreateBackup::dispatch($this->site, 'database', 'manual', $destination->id, $backup->id);
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'stage' => 'failed', 'error_message' => 'Failed to dispatch job: '.$e->getMessage(), 'completed_at' => now()]);
            session()->flash('backup-error', 'Failed to start backup: '.$e->getMessage());

            return;
        }
        $this->trackingBackupId = $backup->id;
        unset($this->activeBackup);
    }

    public function backupFull(): void
    {
        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('backup-error', 'Too many backup requests. Please wait before trying again.');

            return;
        }

        $destination = $this->resolveDestination();
        if (! $destination) {
            session()->flash('backup-error', 'No storage destination configured. Please configure a storage destination in Settings first.');

            return;
        }

        $backup = Backup::create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'pending',
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Backup queued, waiting to start...',
            'includes_database' => true,
            'includes_files' => true,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'plugins_count' => $this->site->sitePlugins()->count(),
            'themes_count' => $this->site->siteThemes()->count(),
            'db_size_mb' => $this->site->db_size_mb,
            'started_at' => now(),
        ]);

        try {
            CreateBackup::dispatch($this->site, 'full', 'manual', $destination->id, $backup->id);
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'stage' => 'failed', 'error_message' => 'Failed to dispatch job: '.$e->getMessage(), 'completed_at' => now()]);
            session()->flash('backup-error', 'Failed to start backup: '.$e->getMessage());

            return;
        }
        $this->trackingBackupId = $backup->id;
        unset($this->activeBackup);
    }

    public function backupIncremental(): void
    {
        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('backup-error', 'Too many backup requests. Please wait before trying again.');

            return;
        }

        $destination = $this->resolveDestination();
        if (! $destination) {
            session()->flash('backup-error', 'No storage destination configured. Please configure a storage destination in Settings first.');

            return;
        }

        // Check if a full backup with manifest exists
        $hasManifest = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereNotNull('manifest_path')
            ->exists();

        if (! $hasManifest) {
            session()->flash('backup-error', 'No full backup with manifest found. Please create a full backup first.');

            return;
        }

        $backup = Backup::create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
            'type' => 'incremental',
            'trigger' => 'manual',
            'status' => 'pending',
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Incremental backup queued, waiting to start...',
            'includes_database' => true,
            'includes_files' => true,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'plugins_count' => $this->site->sitePlugins()->count(),
            'themes_count' => $this->site->siteThemes()->count(),
            'db_size_mb' => $this->site->db_size_mb,
            'started_at' => now(),
        ]);

        try {
            CreateIncrementalBackup::dispatch($this->site, 'manual', $destination->id, $backup->id);
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'stage' => 'failed', 'error_message' => 'Failed to dispatch job: '.$e->getMessage(), 'completed_at' => now()]);
            session()->flash('backup-error', 'Failed to start backup: '.$e->getMessage());

            return;
        }
        $this->trackingBackupId = $backup->id;
        unset($this->activeBackup);
    }

    #[Computed]
    public function hasFullBackupWithManifest(): bool
    {
        return Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereNotNull('manifest_path')
            ->exists();
    }

    public function toggleLock(int $backupId): void
    {
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);
        $backup->update([
            'is_locked' => ! $backup->is_locked,
            'lock_reason' => ! $backup->is_locked ? 'manual' : null,
        ]);
    }

    public function deleteBackup(int $backupId): void
    {
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);

        if ($backup->is_locked) {
            session()->flash('backup-error', 'Cannot delete a locked backup. Unlock it first.');

            return;
        }

        if ($backup->incrementals()->exists()) {
            session()->flash('backup-error', 'Cannot delete a full backup that has incremental backups. Delete the incrementals first.');

            return;
        }

        try {
            if ($backup->storageDestination && $backup->file_path) {
                $driver = StorageFactory::make($backup->storageDestination);
                $driver->delete($backup->file_path);
                $backup->storageDestination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
            }
        } catch (\Exception $e) {
            // Continue with deletion even if storage removal fails
        }

        $backup->delete();
        session()->flash('backup-success', 'Backup deleted.');
    }

    public function updateNotes(int $backupId, string $notes): void
    {
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);
        $backup->update(['notes' => $notes]);
    }

    public function downloadBackup(int $backupId): mixed
    {
        /** @var Backup $backup */
        $backup = $this->site->backups()->with('storageDestination')->findOrFail($backupId);

        if (! $backup->storageDestination || ! $backup->file_path) {
            session()->flash('backup-error', 'Backup file not available for download.');

            return null;
        }

        $destination = $backup->storageDestination;

        if ($destination->type === 'local') {
            $url = URL::signedRoute('backups.download', ['backup' => $backup->id]);

            return $this->redirect($url);
        }

        $driver = StorageFactory::make($destination);
        $url = $driver->temporaryUrl($backup->file_path);

        if (! $url) {
            session()->flash('backup-error', 'Could not generate download link.');

            return null;
        }

        return $this->redirect($url);
    }

    public function pollProgress(): void
    {
        if ($this->trackingBackupId) {
            unset($this->activeBackup);

            $ab = $this->activeBackup;
            if ($ab && in_array($ab->status->value ?? $ab->status, ['completed', 'failed'])) {
                // Let the auto-dismiss timer in Alpine handle cleanup
            }
        }

        if ($this->trackingRestoreBackupId) {
            unset($this->activeRestore);

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

    public function cancelBackup(): void
    {
        if (! $this->trackingBackupId) {
            return;
        }

        $backup = Backup::find($this->trackingBackupId);
        if ($backup && in_array($backup->status, [BackupStatus::Pending, BackupStatus::InProgress])) {
            $backup->update([
                'status' => BackupStatus::Cancelled,
                'stage' => 'cancelled',
                'progress_message' => 'Backup cancelled by user',
                'completed_at' => now(),
                'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
            ]);

            // Release the uniqueness lock so a new backup can be started
            CreateBackup::releaseUniqueLock($this->site->id);
            CreateIncrementalBackup::releaseUniqueLock($this->site->id);
        }

        $this->trackingBackupId = null;
        unset($this->activeBackup);
    }

    public function dismissProgress(): void
    {
        $this->trackingBackupId = null;
        unset($this->activeBackup);
    }

    public function refreshRestoreProgress(): void
    {
        unset($this->activeRestore);
    }

    public function dismissRestoreProgress(): void
    {
        $this->trackingRestoreBackupId = null;
        unset($this->activeRestore);
    }

    #[On('restore-dispatched')]
    public function onRestoreDispatched(int $backupId): void
    {
        $this->trackingRestoreBackupId = $backupId;
        unset($this->activeRestore);
    }

    #[On('schedule-saved')]
    public function refreshData(): void
    {
        unset($this->backupConfig);
    }

    protected function resolveDestination(): ?StorageDestination
    {
        return StorageDestination::resolveForSite($this->site);
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-backups', [
            'backupHistory' => $this->backupHistory,
        ])
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Backups',
            ]);
    }
}
