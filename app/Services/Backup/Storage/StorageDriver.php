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
     * Recursively enumerate every file under $directory, returning a flat list
     * of file entries (not folders). Used by reindex/DR commands that need to
     * walk the full destination. Driver implementations choose the cheapest
     * native API (S3 list-objects-v2 with prefix, Dropbox list_folder
     * recursive=true, Local RecursiveDirectoryIterator).
     *
     * @return list<array{name: string, path: string, size: int, is_dir: bool, modified_at: mixed}>
     */
    public function listRecursive(string $directory = ''): array;

    /**
     * Upload a file using an absolute remote path (bypasses base path prefix).
     */
    public function uploadToAbsolutePath(string $localPath, string $absoluteRemotePath): void;

    /**
     * List folders at an absolute path. Returns empty array if not supported.
     */
    public function listFolders(string $absolutePath = ''): array;

    /**
     * Test the connection to the storage destination.
     */
    public function test(): bool;

    /**
     * Get a temporary download URL for a file. Returns null if not supported.
     */
    public function temporaryUrl(string $remotePath, int $expiresInMinutes = 60): ?string;
}
