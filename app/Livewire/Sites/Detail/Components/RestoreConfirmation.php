<?php

namespace App\Livewire\Sites\Detail\Components;

use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupBrowserService;
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
        $this->confirmed = false;
        $this->backupBeforeRestore = true;
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

    public function setRestoreMode(string $mode): void
    {
        $this->restoreMode = $mode;

        if ($mode === 'selective' && !$this->fileListLoaded && !$this->loadingFileList) {
            // Set loading state and return immediately so the spinner renders,
            // then the blade's wire:poll triggers loadFileList() in a separate request.
            $this->loadingFileList = true;
            $this->fileListError = null;
        }
    }

    public function loadFileList(): void
    {
        if (!$this->backup || $this->fileListLoaded) {
            return;
        }

        $this->loadingFileList = true;
        $this->fileListError = null;

        try {
            $service = new BackupBrowserService();
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
        $tree = [];

        foreach ($files as $file) {
            $parts = explode('/', $file['path']);
            $current = &$tree;

            for ($i = 0; $i < count($parts) - 1; $i++) {
                $dirName = $parts[$i];
                $dirPath = implode('/', array_slice($parts, 0, $i + 1));

                if (!isset($current[$dirName])) {
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

        return array_values(array_merge($dirs, $files));
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

        $restoreDb = $this->restoreMode === 'full' ? true : $this->restoreDatabase;
        $restoreFiles = $this->restoreMode === 'full' ? true : $this->restoreFiles;
        $selectedFiles = ($this->restoreMode === 'selective' && !empty($this->selectedFiles))
            ? $this->selectedFiles
            : [];

        $this->backup->update([
            'restore_status' => 'pending',
            'restore_stage' => 'queued',
            'restore_progress_percent' => 0,
            'restore_progress_message' => 'Restore queued, waiting to start...',
            'restore_error_message' => null,
        ]);

        RestoreBackup::dispatch($this->backup, $restoreDb, $restoreFiles, $selectedFiles);

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
