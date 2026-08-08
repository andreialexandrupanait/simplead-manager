<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Components;

use App\Backup\V2\Enums\RestoreMode;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Enums\BackupEngine;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\BackupBrowserService;
use App\Support\HumanError;
use Livewire\Attributes\On;
use Livewire\Component;

class RestoreConfirmation extends Component
{
    public Site $site;

    public ?Backup $backup = null;

    public bool $confirmed = false;

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

    /**
     * Is the selected restore point one the new engine produced?
     *
     * The two engines write different things to storage, and this component always dispatched the
     * old one's job — while the backup BUTTONS on the same page route by engine
     * ({@see \App\Livewire\Traits\WithBackupActions::startOnNewEngineIfConfigured}). Both engines
     * write rows into `backups`, so as soon as a site moves to V2 its restore points appear in this
     * very list, and "Restore" would hand a V2 restore point to the V1 job. It would not find what
     * it expects and the site would be the place we found out.
     *
     * Routed by the BACKUP's engine rather than the site's, deliberately: a migrated site keeps its
     * old restore points, and each one has to go back through the engine that produced it.
     */
    public function usesNewEngine(): bool
    {
        return $this->backup?->engine === BackupEngine::V2;
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
            $this->fileListError = HumanError::from($e);
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

        // The safety copy is taken inside the restore session now, by the engine
        // that is about to do the restoring — {@see
        // \App\Backup\V2\Restore\PreRestoreSafetyBackup}, mandatory for MIRROR and
        // the backstop the guaranteed rollback falls back to.
        //
        // This screen used to take one first, itself, and then wait for it: two
        // round trips through the modal, a duplicate full backup, and a safety net
        // in the retired engine's format. The engine holds the site's lock for the
        // whole restore, so its copy is also the only one that cannot race the
        // thing it is protecting against.
        $this->dispatchRestore();
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

        // One engine, so one path. A restore point written by the old engine has
        // no session behind it and is refused below by name — which is the honest
        // answer now that the job able to read those archives is gone. They remain
        // ordinary zips in storage (`files/` + `database.sql.gz`) and can be
        // restored by hand.
        $this->dispatchNewEngineRestore($restoreDb, $restoreFiles, $selectedFiles);
    }

    /**
     * Start a restore on the engine that produced this restore point.
     *
     * @param  list<string>  $selectedFiles
     */
    protected function dispatchNewEngineRestore(bool $restoreDb, bool $restoreFiles, array $selectedFiles): void
    {
        $session = BackupSession::where('backup_id', $this->backup?->id)->first();

        if (! $session instanceof BackupSession) {
            // Almost always an archive from the retired engine. Say so plainly and
            // say what can still be done with it, rather than reporting a generic
            // failure on a file that is perfectly intact.
            session()->flash('backup-error', __('This backup was written by the retired engine and cannot be restored from here. The archive is an ordinary zip and can still be restored by hand.'));
            $this->dispatch('close-modal-restore-confirmation');

            return;
        }

        $scope = [
            'database' => $restoreDb,
            'files' => $restoreFiles,
        ];
        if ($selectedFiles !== []) {
            // The engine bounds MIRROR deletions to these roots too, so a selective restore can
            // never reach outside what the person actually picked.
            $scope['paths'] = array_map(static fn ($p): string => trim((string) $p, '/'), $selectedFiles);
        }

        try {
            $restore = app(SessionActions::class)->startRestore($session, RestoreMode::SafeMerge, $scope);
        } catch (\Throwable $e) {
            session()->flash('backup-error', __('Could not start the restore: :error', ['error' => HumanError::from($e)]));
            $this->dispatch('close-modal-restore-confirmation');

            return;
        }

        $this->dispatch('restore-dispatched', backupId: $this->backup?->id);
        $this->dispatch('close-modal-restore-confirmation');

        session()->flash('backup-success', __('Restore queued (session #:id). A safety backup is taken first, and the site is put back exactly as it was if anything fails.', ['id' => $restore->id]));
    }

    public function render()
    {
        return view('livewire.sites.detail.components.restore-confirmation');
    }
}
