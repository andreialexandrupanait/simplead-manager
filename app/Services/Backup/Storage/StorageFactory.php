<?php

declare(strict_types=1);

namespace App\Services\Backup\Storage;

use App\Models\StorageDestination;
use InvalidArgumentException;

class StorageFactory
{
    public static function make(StorageDestination $destination): StorageDriver
    {
        return match ($destination->type) {
            'local' => new LocalDriver($destination->config ?? []),
            'dropbox' => new DropboxDriver($destination),
            's3' => new S3Driver($destination->config ?? []),
            default => throw new InvalidArgumentException("Unsupported storage type: {$destination->type}"),
        };
    }
}
