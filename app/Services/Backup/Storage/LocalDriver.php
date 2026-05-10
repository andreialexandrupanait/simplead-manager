<?php

declare(strict_types=1);

namespace App\Services\Backup\Storage;

use RuntimeException;

class LocalDriver implements StorageDriver
{
    protected string $basePath;

    public function __construct(array $config)
    {
        $this->basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $destination = $this->fullPath($remotePath);
        $dir = dirname($destination);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! copy($localPath, $destination)) {
            throw new RuntimeException("Failed to copy file to {$destination}");
        }
    }

    public function download(string $remotePath, string $localPath): void
    {
        $source = $this->fullPath($remotePath);

        if (! file_exists($source)) {
            throw new RuntimeException("File not found: {$remotePath}");
        }

        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! copy($source, $localPath)) {
            throw new RuntimeException("Failed to download file from {$remotePath}");
        }
    }

    public function delete(string $remotePath): void
    {
        $path = $this->fullPath($remotePath);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->fullPath($remotePath));
    }

    public function size(string $remotePath): int
    {
        $path = $this->fullPath($remotePath);

        if (! file_exists($path)) {
            throw new RuntimeException("File not found: {$remotePath}");
        }

        return filesize($path);
    }

    public function list(string $directory = ''): array
    {
        $path = $this->fullPath($directory);

        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot()) {
                continue;
            }
            $files[] = [
                'name' => $file->getFilename(),
                'path' => ($directory ? $directory.'/' : '').$file->getFilename(),
                'size' => $file->isFile() ? $file->getSize() : 0,
                'is_dir' => $file->isDir(),
                'modified_at' => $file->getMTime(),
            ];
        }

        return $files;
    }

    public function listRecursive(string $directory = ''): array
    {
        $path = $this->fullPath($directory);
        if (! is_dir($path)) {
            return [];
        }

        $base = rtrim($path, '/');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($base)), '/');
            $files[] = [
                'name' => $file->getFilename(),
                'path' => ($directory ? rtrim($directory, '/').'/' : '').$relative,
                'size' => $file->getSize(),
                'is_dir' => false,
                'modified_at' => $file->getMTime(),
            ];
        }

        return $files;
    }

    public function listFolders(string $absolutePath = ''): array
    {
        return [];
    }

    public function uploadToAbsolutePath(string $localPath, string $absoluteRemotePath): void
    {
        $this->upload($localPath, $absoluteRemotePath);
    }

    public function temporaryUrl(string $remotePath, int $expiresInMinutes = 60): ?string
    {
        return null;
    }

    public function test(): bool
    {
        if (! is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }

        $testFile = $this->basePath.'/.storage-test-'.uniqid();
        $written = file_put_contents($testFile, 'test');
        if ($written === false) {
            return false;
        }

        unlink($testFile);

        return true;
    }

    protected function fullPath(string $relativePath): string
    {
        return $this->basePath.'/'.ltrim($relativePath, '/');
    }
}
