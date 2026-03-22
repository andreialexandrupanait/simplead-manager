<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class StorageDestinationFormData extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|in:local,s3,dropbox')]
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

    public string $dropboxReportsPath = '';

    public string $dropboxAppBackupsPath = '';

    /**
     * Whether this is a new destination (affects S3 credential requirements).
     */
    public bool $isCreating = true;

    /**
     * Dynamic validation rules based on storage type and whether editing.
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|in:local,s3,dropbox',
        ];

        if ($this->type === 'local') {
            $rules['localPath'] = 'required|string';
        }

        if ($this->type === 's3') {
            $rules['s3Bucket'] = 'required|string';
            $rules['s3Region'] = 'required|string';

            // Only require key/secret when creating new
            if ($this->isCreating) {
                $rules['s3Key'] = 'required|string';
                $rules['s3Secret'] = 'required|string';
            }
        }

        return $rules;
    }

    public function setFromDestination($destination): void
    {
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
            'dropbox' => (function () use ($config) {
                $this->dropboxBasePath = $config['base_path'] ?? '/#1 SAD Workspace/4. Backup';
                $this->dropboxReportsPath = $config['reports_path'] ?? '';
                $this->dropboxAppBackupsPath = $config['app_backups_path'] ?? '';
            })(),
            default => null,
        };
    }

    public function resetFormData(): void
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
        $this->dropboxReportsPath = '';
        $this->dropboxAppBackupsPath = '';
    }
}
