<?php

declare(strict_types=1);

namespace App\Services\Backup\Storage;

interface StorageDriver
{
    /**
     * Upload a file to the storage destination.
     */
    public function upload(string $localPath, string $remotePath): void;

    /**
     * Download a file from the storage destination.
     */
    public function download(string $remotePath, string $localPath): void;

    /**
     * Delete a file from the storage destination.
     */
    public function delete(string $remotePath): void;

    /**
     * Check if a file exists in the storage destination.
     */
    public function exists(string $remotePath): bool;

    /**
     * Get the size of a file in the storage destination.
     */
    public function size(string $remotePath): int;

    /**
     * List files in a directory in the storage destination.
     */
    public function list(string $directory = ''): array;

    /**
     * Test the connection to the storage destination.
     */
    public function test(): bool;

    /**
     * Get a temporary download URL for a file. Returns null if not supported.
     */
    public function temporaryUrl(string $remotePath, int $expiresInMinutes = 60): ?string;
}
