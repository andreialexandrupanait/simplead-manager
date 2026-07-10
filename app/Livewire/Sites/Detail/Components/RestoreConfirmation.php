<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Components;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Backup\BackupBrowserService;
use Livewire\Attributes\On;
use Livewire\Component;

class RestoreConfirmation extends Component
{
    public Site $site;

    public ?Backup $backup = null;

    public bool $confirmed = false;

    /**
     * Kept for wire compatibility but no longer user-controllable: a safety
     * backup ALWAYS runs before a restore. The only bypass is restoreAnyway()
     * after a FAILED safety backup, gated by typed confirmation.
     */
    public bool $backupBeforeRestore = true;

    /** Must match the site domain to enable restoreAnyway(). */
    public string $confirmDangerText = '';

    public ?int $preRestoreBackupId = null;

    public ?string $preRestoreStatus = null;

    // Selective restore properties
    public string $restoreMode = 'full'; // 'full' | 'selective'

    public bool $restoreDatabase = true;

    public bool $restoreFiles = true;

    public array $fileTree = [];

    public array $selectedFiles = [];

    public bool $loadingFileList = false;

    public bool $fileListLoaded = false;

    public ?string $fileListError = null;

    public int $totalFileCount = 0;

    public bool $fileListTruncated = false;

    public bool $hasDatabase = false;

    public bool $hasFiles = false;

    #[On('open-restore-confirmation')]
    public function openModal(int $backupId): void
    {
        $this->backup = Backup::with(['site', 'storageDestination'])->findOrFail($backupId);
        $this->authorizeRestore();
        $this->confirmed = false;
        $this->backupBeforeRestore = true;
        $this->confirmDangerText = '';
        $this->preRestoreBackupId = null;
        $this->preRestoreStatus = null;

        // Reset selective restore state
        $this->restoreMode = 'full';
        $this->restoreDatabase = true;
        $this->restoreFiles = true;
        $this->fileTree = [];
        $this->selectedFiles = [];
        $this->loadingFileList = false;
        $this->fileListLoaded = false;
        $this->fileListError = null;
        $this->totalFileCount = 0;
        $this->fileListTruncated = false;
        $this->hasDatabase = (bool) $this->backup->includes_database;
        $this->hasFiles = (bool) $this->backup->includes_files;

        $this->dispatch('open-modal-restore-confirmation');
    }

    /**
     * Guard every restore-related entry point: the backup must belong to the
     * site this component was mounted for (blocks cross-tenant IDOR via a
     * tampered backup id/model) and the current user must be allowed to
     * restore it (BackupPolicy denies viewers and non-owners).
     */
    protected function authorizeRestore(): void
    {
        if (! $this->backup) {
            abort(404);
        }

        if ($this->backup->site_id !== $this->site->id) {
            abort(403, 'This backup does not belong to this site.');
        }

        $this->authorize('restore', $this->backup);
    }

    public function setRestoreMode(string $mode): void
    {
        $this->restoreMode = $mode;

        if ($mode === 'selective' && ! $this->fileListLoaded && ! $this->loadingFileList) {
            // Set loading state and return immediately so the spinner renders,
            // then the blade's wire:poll triggers loadFileList() in a separate request.
            $this->loadingFileList = true;
            $this->fileListError = null;
        }
    }

    public function loadFileList(): void
    {
        if (! $this->backup || $this->fileListLoaded) {
            return;
        }

        $this->authorizeRestore();

        $this->loadingFileList = true;
        $this->fileListError = null;

        try {
            $service = new BackupBrowserService;
            $result = $service->listContents($this->backup);

            $this->hasDatabase = $result['has_database'];
            $this->hasFiles = $result['has_files'];
            $this->totalFileCount = $result['file_count'];
            $this->fileListTruncated = $result['truncated'];
            $this->fileTree = $this->buildTree($result['files']);
            $this->fileListLoaded = true;
        } catch (\Exception $e) {
            $this->fileListError = $e->getMessage();
        } finally {
            $this->loadingFileList = false;
        }
    }

    /**
     * Convert a flat file list into a nested tree structure.
     */
    protected function buildTree(array $files): array
    {
        /** @var array<string, mixed> $tree */
        $tree = [];

        foreach ($files as $file) {
            $parts = explode('/', $file['path']);
            $current = &$tree;

            for ($i = 0; $i < count($parts) - 1; $i++) {
                $dirName = $parts[$i];
                $dirPath = implode('/', array_slice($parts, 0, $i + 1));

                if (! isset($current[$dirName])) {
                    $current[$dirName] = [
                        'type' => 'dir',
                        'name' => $dirName,
                        'path' => $dirPath,
                        'children' => [],
                    ];
                }

                $current = &$current[$dirName]['children'];
            }

            $fileName = end($parts);
            $current[$fileName] = [
                'type' => 'file',
                'name' => $fileName,
                'path' => $file['path'],
                'size' => $file['size'],
            ];
        }

        return $this->sortTree($tree);
    }

    /**
     * Sort tree: directories first (alphabetical), then files (alphabetical).
     */
    protected function sortTree(array $tree): array
    {
        $dirs = [];
        $files = [];

        foreach ($tree as $node) {
            if ($node['type'] === 'dir') {
                $node['children'] = $this->sortTree($node['children']);
                $dirs[] = $node;
            } else {
                $files[] = $node;
            }
        }

        usort($dirs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return array_merge($dirs, $files);
    }

    public function restore(): void
    {
        if (! $this->confirmed || ! $this->backup) {
            return;
        }

        // The safety backup is mandatory — the checkbox no longer bypasses it.
        if (! $this->preRestoreBackupId) {
            $this->startPreRestoreBackup();

            return;
        }

        // If pre-restore backup is still running, don't proceed
        if ($this->preRestoreStatus && ! in_array($this->preRestoreStatus, ['completed', 'failed'])) {
            return;
        }

        if ($this->preRestoreStatus === 'failed') {
            // A failed safety backup never falls through to a silent restore —
            // that path is restoreAnyway(), with its own typed confirmation.
            return;
        }

        $this->dispatchRestore();
    }

    /**
     * Explicit, typed-confirmation bypass — ONLY reachable after a safety
     * backup was attempted and failed. Restoring a live site with no safety
     * net is the most dangerous action in the product; make it deliberate.
     */
    public function restoreAnyway(): void
    {
        if (! $this->confirmed || ! $this->backup) {
            return;
        }

        $this->authorizeRestore();

        if ($this->preRestoreStatus !== 'failed') {
            abort(403, 'Restore-anyway is only available after a failed safety backup.');
        }

        $expected = $this->site->domain;
        if ($expected === '' || ! hash_equals($expected, trim($this->confirmDangerText))) {
            $this->addError('confirmDangerText', __('Type the site domain exactly to confirm restoring without a safety backup.'));

            return;
        }

        ActivityLogger::log(
            type: 'backup',
            severity: 'critical',
            title: "SAFETY BACKUP SKIPPED — restore forced on {$this->site->name}",
            description: 'User bypassed a failed pre-restore safety backup with typed confirmation.',
            site: $this->site,
            metadata: ['backup_id' => $this->backup->id, 'pre_restore_backup_id' => $this->preRestoreBackupId],
            icon: 'alert-triangle',
        );

        $this->dispatchRestore(safetyBackupSkipped: true);
    }

    public function checkPreRestoreStatus(): void
    {
        if (! $this->preRestoreBackupId) {
            return;
        }

        $preBackup = Backup::find($this->preRestoreBackupId);
        if (! $preBackup) {
            return;
        }

        $this->preRestoreStatus = $preBackup->status->value;

        if ($preBackup->status === BackupStatus::Completed) {
            // Auto-dispatch restore now
            $this->dispatchRestore();
        }
    }

    protected function startPreRestoreBackup(): void
    {
        $this->authorizeRestore();

        $destination = $this->resolveDestination();
        if (! $destination) {
            session()->flash('backup-error', 'No storage destination configured.');
            $this->dispatch('close-modal-restore-confirmation');

            return;
        }

        // A restore that touches files gets a FULL safety backup — a DB-only
        // snapshot cannot undo overwritten themes/plugins/uploads (B-P0-2).
        $includesFiles = $this->restoreMode === 'full' || $this->restoreFiles;
        $safetyType = $includesFiles ? 'full' : 'database';

        $preBackup = Backup::create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
            'type' => $safetyType,
            'trigger' => 'pre_restore',
            'status' => 'pending',
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Creating safety backup before restore...',
            'includes_database' => true,
            'includes_files' => $includesFiles,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'is_locked' => true,
            'lock_reason' => 'pre-restore',
            'started_at' => now(),
        ]);

        CreateBackup::dispatch($this->site, $safetyType, 'pre_restore', $destination->id, $preBackup->id);

        $this->preRestoreBackupId = $preBackup->id;
        $this->preRestoreStatus = 'pending';
    }

    protected function dispatchRestore(bool $safetyBackupSkipped = false): void
    {
        if (! $this->backup) {
            return;
        }

        $this->authorizeRestore();

        $restoreDb = $this->restoreMode === 'full' ? true : $this->restoreDatabase;
        $restoreFiles = $this->restoreMode === 'full' ? true : $this->restoreFiles;
        $selectedFiles = ($this->restoreMode === 'selective' && ! empty($this->selectedFiles))
            ? $this->selectedFiles
            : [];

        $this->backup->update([
            'restore_status' => 'pending',
            'restore_stage' => 'queued',
            'restore_progress_percent' => 0,
            'restore_progress_message' => 'Restore queued, waiting to start...',
            'restore_error_message' => null,
        ]);

        RestoreBackup::dispatch($this->backup, $restoreDb, $restoreFiles, $selectedFiles, $safetyBackupSkipped);

        $this->dispatch('restore-dispatched', backupId: $this->backup->id);
        $this->dispatch('close-modal-restore-confirmation');

        $mode = $this->restoreMode === 'selective' ? 'Selective restore' : 'Full restore';
        session()->flash('backup-success', "{$mode} has been queued. The site will be restored from this backup.");
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
