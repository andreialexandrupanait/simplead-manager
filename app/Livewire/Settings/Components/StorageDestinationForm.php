<?php

declare(strict_types=1);

namespace App\Livewire\Settings\Components;

use App\Livewire\Forms\StorageDestinationFormData;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Livewire\Attributes\On;
use Livewire\Component;

class StorageDestinationForm extends Component
{
    public ?int $destinationId = null;

    public StorageDestinationFormData $form;

    // Dropbox folder browser
    public bool $showFolderBrowser = false;

    public string $browserTarget = 'base_path';

    public string $browserCurrentPath = '';

    public array $browserFolders = [];

    public string $browserError = '';

    #[On('open-storage-form')]
    public function openModal(?int $destinationId = null): void
    {
        $this->resetValidation();
        $this->destinationId = $destinationId;

        if ($destinationId) {
            $destination = StorageDestination::findOrFail($destinationId);
            $this->form->setFromDestination($destination);
            $this->form->isCreating = false;
        } else {
            $this->form->resetFormData();
            $this->form->isCreating = true;
        }

        $this->showFolderBrowser = false;
        $this->browserTarget = 'base_path';
        $this->browserCurrentPath = '';
        $this->browserFolders = [];
        $this->browserError = '';

        $this->dispatch('open-modal-storage-form');
    }

    public function save(): void
    {
        $this->form->validate();

        $config = match ($this->form->type) {
            'local' => ['path' => $this->form->localPath],
            's3' => $this->buildS3Config(),
            'dropbox' => $this->buildDropboxConfig(),
            default => [],
        };

        $data = [
            'name' => $this->form->name,
            'type' => $this->form->type,
            'config' => $config,
            'is_default' => $this->form->is_default,
        ];

        if ($this->form->is_default) {
            StorageDestination::where('is_default', true)->update(['is_default' => false]);
        }

        if ($this->destinationId) {
            $destination = StorageDestination::findOrFail($this->destinationId);
            // For S3: merge existing encrypted credentials if not re-entered
            if ($this->form->type === 's3') {
                $existingConfig = $destination->config ?? [];
                if (empty($this->form->s3Key) && isset($existingConfig['key'])) {
                    $config['key'] = $existingConfig['key'];
                }
                if (empty($this->form->s3Secret) && isset($existingConfig['secret'])) {
                    $config['secret'] = $existingConfig['secret'];
                }
                $data['config'] = $config;
            }
            // For Dropbox: merge existing tokens and root_namespace_id
            if ($this->form->type === 'dropbox') {
                $existingConfig = $destination->config ?? [];
                $data['config'] = array_merge($existingConfig, $config);
            }
            $destination->update($data);
        } else {
            StorageDestination::create($data);
        }

        $this->dispatch('close-modal-storage-form');
        $this->dispatch('storage-destination-saved');
    }

    protected function buildS3Config(): array
    {
        $config = [
            'bucket' => $this->form->s3Bucket,
            'region' => $this->form->s3Region,
            'endpoint' => $this->form->s3Endpoint,
            'base_path' => $this->form->s3BasePath,
        ];

        if (! empty($this->form->s3Key)) {
            $config['key'] = encrypt($this->form->s3Key);
        }
        if (! empty($this->form->s3Secret)) {
            $config['secret'] = encrypt($this->form->s3Secret);
        }

        return $config;
    }

    protected function buildDropboxConfig(): array
    {
        return [
            'base_path' => $this->form->dropboxBasePath,
            'reports_path' => $this->form->dropboxReportsPath,
            'app_backups_path' => $this->form->dropboxAppBackupsPath,
        ];
    }

    public function openFolderBrowser(string $target = 'base_path'): void
    {
        $this->browserTarget = $target;
        $this->showFolderBrowser = true;
        $this->browserError = '';
        $this->browseTo('');
    }

    public function closeFolderBrowser(): void
    {
        $this->showFolderBrowser = false;
        $this->browserTarget = 'base_path';
        $this->browserCurrentPath = '';
        $this->browserFolders = [];
        $this->browserError = '';
    }

    public function browseTo(string $path): void
    {
        $this->browserError = '';

        $destination = $this->destinationId
            ? StorageDestination::find($this->destinationId)
            : StorageDestination::where('type', 'dropbox')->first();

        if (! $destination || $destination->type !== 'dropbox') {
            $this->browserError = 'No Dropbox connection found. Please connect Dropbox first.';
            $this->browserFolders = [];

            return;
        }

        try {
            $driver = StorageFactory::make($destination);
            $this->browserFolders = $driver->listFolders($path);
            $this->browserCurrentPath = $path;
        } catch (DecryptException) {
            $this->browserError = 'Dropbox credentials could not be decrypted. Please reconnect Dropbox.';
            $this->browserFolders = [];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'could not be decrypted') || str_contains($message, 'payload is invalid')) {
                $this->browserError = 'Dropbox credentials could not be decrypted. Please reconnect Dropbox.';
            } elseif (str_contains($message, '401') || str_contains($message, 'expired')) {
                $this->browserError = 'Dropbox token expired. Please reconnect Dropbox.';
            } elseif (str_contains($message, 'not_found')) {
                $this->browserError = 'Folder not found.';
            } else {
                $this->browserError = 'Could not load folders: '.$message;
            }
            $this->browserFolders = [];
        }
    }

    public function browseUp(): void
    {
        if ($this->browserCurrentPath === '' || $this->browserCurrentPath === '/') {
            return;
        }

        $parent = dirname($this->browserCurrentPath);
        $this->browseTo($parent === '/' || $parent === '.' ? '' : $parent);
    }

    public function selectCurrentFolder(): void
    {
        $selected = $this->browserCurrentPath === '' ? '/' : $this->browserCurrentPath;
        $this->applyBrowserSelection($selected);
        $this->closeFolderBrowser();
    }

    public function selectFolder(string $path): void
    {
        $this->applyBrowserSelection($path);
        $this->closeFolderBrowser();
    }

    protected function applyBrowserSelection(string $path): void
    {
        match ($this->browserTarget) {
            'reports_path' => $this->form->dropboxReportsPath = $path,
            'app_backups_path' => $this->form->dropboxAppBackupsPath = $path,
            default => $this->form->dropboxBasePath = $path,
        };
    }

    public function render()
    {
        return view('livewire.settings.components.storage-destination-form');
    }
}
