<?php

declare(strict_types=1);

namespace App\Services\Backup;

use ZipArchive;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Build a single .zip backup archive on local disk by stream-copying entries
 * out of the WP plugin's downloaded chunk zips into one consolidated output zip
 * with proper WordPress folder structure.
 *
 * The chunk zips that the WP connector plugin produces ALREADY contain proper
 * WP file paths inside (wp-content/plugins/..., wp-admin/..., etc.) — they're
 * just split at ~50 MB boundaries for pipelined HTTP transport. This builder
 * un-chunks them so the user-visible backup.zip looks like a normal WP archive
 * instead of leaking transport artefacts (files_chunk_N.zip).
 *
 * Memory bound: ~64 KB write buffer + 8 KB read buffer per entry. Total RAM
 * usage is independent of backup size.
 *
 * Disk bound: chunk_paths can be deleted by the caller as soon as
 * addEntriesFromZip returns — output zip is written immediately on each add
 * (ZipStream's design). Peak local disk = output zip in progress + 1 chunk
 * being read.
 */
class BackupZipBuilder
{
    /** @var resource */
    private $outputStream;

    private ZipStream $zip;

    private int $entriesAdded = 0;

    private bool $finished = false;

    public function __construct(private readonly string $outputPath)
    {
        $stream = @fopen($outputPath, 'wb');
        if ($stream === false) {
            throw new \RuntimeException("BackupZipBuilder: cannot open output for write: {$outputPath}");
        }
        $this->outputStream = $stream;
        $this->zip = new ZipStream(
            outputStream: $this->outputStream,
            sendHttpHeaders: false,
            defaultCompressionMethod: CompressionMethod::STORE, // backups are mostly already-compressed assets; no point re-compressing
            defaultEnableZeroHeader: true,
        );
    }

    public function addFileFromPath(string $localPath, string $entryName): void
    {
        $this->ensureNotFinished();

        $stream = @fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("BackupZipBuilder: cannot read {$localPath}");
        }
        try {
            $this->zip->addFileFromStream(fileName: $entryName, stream: $stream);
        } finally {
            fclose($stream);
        }
        $this->entriesAdded++;
    }

    public function addString(string $entryName, string $content): void
    {
        $this->ensureNotFinished();
        $this->zip->addFile(fileName: $entryName, data: $content);
        $this->entriesAdded++;
    }

    /**
     * Stream-copy each entry of $chunkZipPath into the output zip under $pathPrefix.
     * Source chunk file is NOT extracted to disk — entries are read via
     * ZipArchive::getStream() and piped through.
     *
     * Caller may unlink $chunkZipPath after this returns.
     *
     * @return int number of entries copied
     */
    public function addEntriesFromZip(string $chunkZipPath, string $pathPrefix = ''): int
    {
        $this->ensureNotFinished();

        $reader = new ZipArchive;
        $openResult = $reader->open($chunkZipPath);
        if ($openResult !== true) {
            throw new \RuntimeException("BackupZipBuilder: cannot open chunk zip {$chunkZipPath} (code {$openResult})");
        }

        $prefix = $pathPrefix === '' ? '' : rtrim($pathPrefix, '/').'/';
        $count = 0;

        try {
            for ($i = 0; $i < $reader->numFiles; $i++) {
                $stat = $reader->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $entryName = $stat['name'];

                // ZipArchive lists directory entries with trailing slash; skip them
                if (str_ends_with($entryName, '/')) {
                    continue;
                }

                $stream = $reader->getStream($entryName);
                if ($stream === false) {
                    throw new \RuntimeException("BackupZipBuilder: cannot stream-read entry '{$entryName}' from {$chunkZipPath}");
                }

                try {
                    $this->zip->addFileFromStream(
                        fileName: $prefix.ltrim($entryName, '/'),
                        stream: $stream
                    );
                } finally {
                    fclose($stream);
                }

                $count++;
            }
        } finally {
            $reader->close();
        }

        $this->entriesAdded += $count;

        return $count;
    }

    /**
     * Flush central directory, close the file. Returns final stats.
     *
     * @return array{path: string, size: int, sha256: string, entries: int}
     */
    public function finish(): array
    {
        $this->ensureNotFinished();

        $this->zip->finish();
        fclose($this->outputStream);
        $this->finished = true;

        clearstatcache(true, $this->outputPath);
        $size = (int) filesize($this->outputPath);
        $sha256 = hash_file('sha256', $this->outputPath);
        if ($sha256 === false) {
            throw new \RuntimeException("BackupZipBuilder: hash_file failed on {$this->outputPath}");
        }

        return [
            'path' => $this->outputPath,
            'size' => $size,
            'sha256' => $sha256,
            'entries' => $this->entriesAdded,
        ];
    }

    /**
     * Abort and discard the in-progress output. Safe to call multiple times.
     */
    public function abort(): void
    {
        if ($this->finished) {
            return;
        }
        if (is_resource($this->outputStream)) {
            @fclose($this->outputStream);
        }
        @unlink($this->outputPath);
        $this->finished = true;
    }

    private function ensureNotFinished(): void
    {
        if ($this->finished) {
            throw new \LogicException('BackupZipBuilder: already finished or aborted');
        }
    }
}
