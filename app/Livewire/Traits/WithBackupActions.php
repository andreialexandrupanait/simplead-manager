<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\ExportBackupForLocal;
use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

trait WithBackupActions
{
    public function backupDatabase(): void
    {
        $this->authorizeSiteModification($this->site);
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
        $this->authorizeSiteModification($this->site);
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
        $this->authorizeSiteModification($this->site);
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

    public function toggleLock(int $backupId): void
    {
        $this->authorizeSiteModification($this->site);
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);
        $backup->update([
            'is_locked' => ! $backup->is_locked,
            'lock_reason' => ! $backup->is_locked ? 'manual' : null,
        ]);
    }

    public function deleteBackup(int $backupId): void
    {
        $this->authorizeSiteModification($this->site);
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

        // P1-28: remove ALL artifacts (primary + replicas + sidecar + manifest +
        // multipart prefix) across every destination, not just the primary file.
        app(\App\Services\Backup\RetentionService::class)->purge($backup);
        session()->flash('backup-success', 'Backup deleted.');
        $this->resetPage();
    }

    public function bulkDelete(array $ids): void
    {
        $this->authorizeSiteModification($this->site);
        $backups = $this->site->backups()
            ->whereIn('id', $ids)
            ->where('is_locked', false)
            ->get();

        $count = 0;
        $retention = app(\App\Services\Backup\RetentionService::class);
        /** @var \App\Models\Backup $backup */
        foreach ($backups as $backup) {
            if ($backup->incrementals()->exists()) {
                continue;
            }

            // P1-28: full artifact cleanup across every destination.
            $retention->purge($backup);
            $count++;
        }

        $skipped = count($ids) - $count;
        $msg = "{$count} backup(s) deleted.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (locked or has incrementals).";
        }
        session()->flash('backup-success', $msg);
        $this->resetPage();
    }

    public function updateNotes(int $backupId, string $notes): void
    {
        $this->authorizeSiteModification($this->site);
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);
        $backup->update(['notes' => $notes]);
    }

    /**
     * On-demand restore test: verify this backup is actually restorable
     * (download → extract → parse DB dump → check files) without touching the
     * live site. Result lands on the backup's verification_status.
     */
    public function verifyBackupNow(int $backupId): void
    {
        $this->authorizeSiteModification($this->site);
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);

        $backup->update(['verification_status' => 'testing']);
        \App\Jobs\RunBackupVerification::dispatch($backup);

        session()->flash('backup-success', "Restore test queued for backup #{$backup->id} — the result will show here shortly.");
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

    public function exportBackupForLocal(int $backupId): void
    {
        /** @var Backup $backup */
        $backup = $this->site->backups()->findOrFail($backupId);

        if ($backup->status !== BackupStatus::Completed) {
            session()->flash('backup-error', 'Backup is not ready yet.');

            return;
        }

        if ($backup->localExportInProgress()) {
            session()->flash('backup-info', 'Local export is already in progress.');

            return;
        }

        $backup->update([
            'local_export_status' => 'pending',
            'local_export_error' => null,
        ]);

        ExportBackupForLocal::dispatch($backup->id);

        session()->flash('backup-success', 'Local-compatible export queued. The list will refresh when it is ready to download.');
    }

    public function downloadBackupForLocal(int $backupId): mixed
    {
        /** @var Backup $backup */
        $backup = $this->site->backups()->with('storageDestination')->findOrFail($backupId);

        if (! $backup->localExportReady() || ! $backup->storageDestination) {
            session()->flash('backup-error', 'Local export is not ready for download.');

            return null;
        }

        $destination = $backup->storageDestination;

        if ($destination->type === 'local') {
            $url = URL::signedRoute('backups.download-local', ['backup' => $backup->id]);

            return $this->redirect($url);
        }

        $driver = StorageFactory::make($destination);
        $url = $driver->temporaryUrl($backup->local_export_file_path);

        if (! $url) {
            session()->flash('backup-error', 'Could not generate Local export download link.');

            return null;
        }

        return $this->redirect($url);
    }

    public function cancelBackup(): void
    {
        $this->authorizeSiteModification($this->site);
        if (! $this->trackingBackupId) {
            return;
        }

        // P1-29: $trackingBackupId is a client-hydrated Livewire property.
        // Scope the lookup to THIS site's backups so a tampered id pointing at
        // another tenant's backup resolves to null and cannot be cancelled.
        /** @var Backup|null $backup */
        $backup = $this->site->backups()->find($this->trackingBackupId);
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

    protected function resolveDestination(): ?StorageDestination
    {
        return StorageDestination::resolveForSite($this->site);
    }
}
