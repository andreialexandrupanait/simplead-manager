<?php

declare(strict_types=1);

namespace App\Services\AppBackup;

use App\Models\AppBackup;

trait AppBackupHelpers
{
    protected function exec(string $command): string
    {
        $output = [];
        $returnVar = 0;
        exec($command.' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            $outputStr = implode("\n", $output);
            throw new \RuntimeException("Command failed (exit code {$returnVar}): {$outputStr}");
        }

        return implode("\n", $output);
    }

    protected function updateProgress(AppBackup $backup, int $progress): void
    {
        $backup->update(['progress' => $progress]);
    }

    protected function log(AppBackup $backup, string $message): void
    {
        $log = $backup->log ?? [];
        $log[] = [
            'time' => now()->format('H:i:s'),
            'message' => $message,
        ];
        $backup->update(['log' => $log]);
    }

    protected function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }
}
