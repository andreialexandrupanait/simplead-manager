<?php

namespace App\Livewire\Settings\Components;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Livewire\Attributes\On;
use Livewire\Component;

class StorageDestinationForm extends Component
{
    public ?int $destinationId = null;
    public string $name = '';
    public string $type = 'local';
    public bool $is_default = false;

    // Local
    public string $localPath = '';

    // S3
    public string $s3Key = '';
    public string $s3Secret = '';
    public string $s3Bucket = '';
    public string $s3Region = 'us-east-1';
    public string $s3Endpoint = '';
    public string $s3BasePath = '';

    // Dropbox
    public string $dropboxBasePath = '/#1 SAD Workspace/4. Backup';

    // Dropbox folder browser
    public bool $showFolderBrowser = false;
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
            $this->name = $destination->name;
            $this->type = $destination->type;
            $this->is_default = $destination->is_default;
            $config = $destination->config ?? [];

            match ($destination->type) {
                'local' => $this->localPath = $config['path'] ?? '',
                's3' => (function () use ($config) {
                    $this->s3Key = '';  // Don't show encrypted values
                    $this->s3Secret = '';
                    $this->s3Bucket = $config['bucket'] ?? '';
                    $this->s3Region = $config['region'] ?? 'us-east-1';
                    $this->s3Endpoint = $config['endpoint'] ?? '';
                    $this->s3BasePath = $config['base_path'] ?? '';
                })(),
                'dropbox' => $this->dropboxBasePath = $config['base_path'] ?? '/#1 SAD Workspace/4. Backup',
                default => null,
            };
        } else {
            $this->resetForm();
        }

        $this->dispatch('open-modal-storage-form');
    }

    protected function resetForm(): void
    {
        $this->name = '';
        $this->type = 'local';
        $this->is_default = false;
        $this->localPath = '';
        $this->s3Key = '';
        $this->s3Secret = '';
        $this->s3Bucket = '';
        $this->s3Region = 'us-east-1';
        $this->s3Endpoint = '';
        $this->s3BasePath = '';
        $this->dropboxBasePath = '/#1 SAD Workspace/4. Backup';
        $this->showFolderBrowser = false;
        $this->browserCurrentPath = '';
        $this->browserFolders = [];
        $this->browserError = '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:local,s3,dropbox',
        ]);

        if ($this->type === 'local') {
            $this->validate(['localPath' => 'required|string']);
        }

        if ($this->type === 's3') {
            $rules = [
                's3Bucket' => 'required|string',
                's3Region' => 'required|string',
            ];
            // Only require key/secret when creating new
            if (!$this->destinationId) {
                $rules['s3Key'] = 'required|string';
                $rules['s3Secret'] = 'required|string';
            }
            $this->validate($rules);
        }

        $config = match ($this->type) {
            'local' => ['path' => $this->localPath],
            's3' => $this->buildS3Config(),
            'dropbox' => $this->buildDropboxConfig(),
            default => [],
        };

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'config' => $config,
            'is_default' => $this->is_default,
        ];

        if ($this->is_default) {
            StorageDestination::where('is_default', true)->update(['is_default' => false]);
        }

        if ($this->destinationId) {
            $destination = StorageDestination::findOrFail($this->destinationId);
            // For S3: merge existing encrypted credentials if not re-entered
            if ($this->type === 's3') {
                $existingConfig = $destination->config ?? [];
                if (empty($this->s3Key) && isset($existingConfig['key'])) {
                    $config['key'] = $existingConfig['key'];
                }
                if (empty($this->s3Secret) && isset($existingConfig['secret'])) {
                    $config['secret'] = $existingConfig['secret'];
                }
                $data['config'] = $config;
            }
            // For Dropbox: merge existing tokens and root_namespace_id
            if ($this->type === 'dropbox') {
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
            'bucket' => $this->s3Bucket,
            'region' => $this->s3Region,
            'endpoint' => $this->s3Endpoint,
            'base_path' => $this->s3BasePath,
        ];

        if (!empty($this->s3Key)) {
            $config['key'] = encrypt($this->s3Key);
        }
        if (!empty($this->s3Secret)) {
            $config['secret'] = encrypt($this->s3Secret);
        }

        return $config;
    }

    protected function buildDropboxConfig(): array
    {
        return [
            'base_path' => $this->dropboxBasePath,
        ];
    }

    public function openFolderBrowser(): void
    {
        $this->showFolderBrowser = true;
        $this->browserError = '';
        $this->browseTo('');
    }

    public function closeFolderBrowser(): void
    {
        $this->showFolderBrowser = false;
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
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if (str_contains($message, '401') || str_contains($message, 'expired')) {
                $this->browserError = 'Dropbox token expired. Please reconnect Dropbox.';
            } elseif (str_contains($message, 'not_found')) {
                $this->browserError = 'Folder not found.';
            } else {
                $this->browserError = 'Could not load folders: ' . $message;
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
        $this->dropboxBasePath = $this->browserCurrentPath === '' ? '/' : $this->browserCurrentPath;
        $this->closeFolderBrowser();
    }

    public function selectFolder(string $path): void
    {
        $this->dropboxBasePath = $path;
        $this->closeFolderBrowser();
    }

    public function render()
    {
        return view('livewire.settings.components.storage-destination-form');
    }
}
