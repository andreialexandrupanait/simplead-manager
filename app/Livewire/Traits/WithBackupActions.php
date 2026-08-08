<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Models\Backup;
use App\Services\Backup\BackupLauncher;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

trait WithBackupActions
{
    public function backupDatabase(): void
    {
        $this->startManualBackup('database');
    }

    public function backupFull(): void
    {
        $this->startManualBackup('full');
    }

    public function backupIncremental(): void
    {
        $this->startManualBackup('incremental');
    }

    /**
     * The one path behind all three buttons.
     *
     * They used to be three near-identical copies, each with its own engine check
     * and its own row-creation block, and the copies had drifted apart in ways a
     * person could feel:
     *
     * - "Backup Database" called startOnNewEngineIfConfigured('full'), so on the
     *   new engine it silently made a FULL backup while the button still said
     *   Database. The engine could always scope a dump on its own
     *   (SessionActions::defaultScope) — nothing ever asked it to.
     * - The rate limit, the "no storage destination" message and the "run a full
     *   backup first" guard all sat AFTER the early return, so none of them
     *   applied to a site on the new engine. A person could hold the button down.
     *
     * Which engine runs it, and what each engine needs before it can, now live in
     * BackupLauncher. What is left here is what this layer is actually for: who is
     * allowed, how often, and what the screen says afterwards.
     */
    private function startManualBackup(string $type): void
    {
        $this->authorizeSiteModification($this->site);

        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('backup-error', 'Too many backup requests. Please wait before trying again.');

            return;
        }

        try {
            $backup = app(BackupLauncher::class)->launch($this->site, $type, 'manual');
        } catch (\Throwable $e) {
            session()->flash('backup-error', $e->getMessage());

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

        // On a V2 row file_path is a prefix, so a presigned URL for it points at
        // nothing. What the owner needs is the portable package: one zip of
        // files/ + database.sql.gz, rebuilt by replaying the chain and
        // decrypting — the same shape the old engine produced, openable with any
        // unzip tool and importable with any mysql client.
        //
        // Building it means pulling every chunk out of storage, so it is queued
        // and the link appears once it is ready rather than blocking a web
        // worker for minutes.
        if ($backup->engine === BackupEngine::V2) {
            return $this->downloadV2Backup($backup);
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

    /**
     * Hand back the portable package, building it if it does not exist yet.
     *
     * Kept as a two-step — ask, then download — rather than pretending to be
     * instant. Replaying a chain across storage takes minutes for a real site,
     * and a button that appears to work while quietly doing nothing is worse
     * than one that says what it is doing.
     */
    protected function downloadV2Backup(Backup $backup): mixed
    {
        $session = \App\Backup\V2\Models\BackupSession::query()
            ->where('backup_id', $backup->id)
            ->first();

        if (! $session instanceof \App\Backup\V2\Models\BackupSession) {
            session()->flash('backup-error', 'This backup has no session to rebuild a package from.');

            return null;
        }

        $key = (string) (($session->checkpoint['portable_key'] ?? null) ?: '');

        if ($key !== '') {
            $driver = StorageFactory::make($backup->storageDestination);
            $url = $driver->temporaryUrl($key);

            if ($url) {
                return $this->redirect($url);
            }
        }

        \App\Backup\V2\Jobs\BuildPortablePackageJob::dispatch($session->id);
        session()->flash(
            'backup-success',
            'Preparing a downloadable archive of this backup. It takes a few minutes; the download will be ready here.',
        );

        return null;
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
}
