<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Convert a SimpleAd v3-zip backup archive into the layout Local by Flywheel
 * expects on import:
 *
 *   source v3-zip:                          ->   Local-compatible zip:
 *   ├── files/wp-admin/...                       ├── app/public/wp-admin/...
 *   ├── files/wp-content/...                     ├── app/public/wp-content/...
 *   ├── database.sql.gz                          ├── app/sql/local.sql        (gunzipped)
 *   └── backup-meta.json                         └── (omitted)
 *
 * Streaming: entries are piped through ZipArchive::getStream() into ZipStream
 * with no decompression-to-disk for file entries. The SQL dump is the only
 * special case: its bytes are gzipped at the SQL level, so we extract the .gz
 * payload to a temp file and re-stream through PHP's compress.zlib:// wrapper
 * to gunzip on the fly before writing into the output zip.
 *
 * RAM is bounded (~few KB buffers); disk peak adds one extracted gz of the SQL
 * dump (deleted at finish()).
 */
class LocalFlywheelRepackager
{
    /** @var resource */
    private $outputStream;

    private ZipStream $zip;

    private int $entriesAdded = 0;

    /** Tracked so we can clean up the gunzip tmp even on exceptions. */
    private ?string $sqlTmpPath = null;

    public function __construct(
        private readonly string $sourceV3ZipPath,
        private readonly string $outputPath,
    ) {}

    /**
     * @return array{path: string, size: int, sha256: string, entries: int}
     */
    public function repackage(): array
    {
        if (! is_file($this->sourceV3ZipPath)) {
            throw new RuntimeException("LocalFlywheelRepackager: source not found: {$this->sourceV3ZipPath}");
        }

        $stream = @fopen($this->outputPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException("LocalFlywheelRepackager: cannot open output for write: {$this->outputPath}");
        }
        $this->outputStream = $stream;

        $this->zip = new ZipStream(
            outputStream: $this->outputStream,
            sendHttpHeaders: false,
            defaultCompressionMethod: CompressionMethod::STORE,
            defaultEnableZeroHeader: true,
        );

        $reader = new ZipArchive;
        $openResult = $reader->open($this->sourceV3ZipPath);
        if ($openResult !== true) {
            @fclose($this->outputStream);
            @unlink($this->outputPath);
            throw new RuntimeException("LocalFlywheelRepackager: cannot open source zip (code {$openResult})");
        }

        try {
            for ($i = 0; $i < $reader->numFiles; $i++) {
                $stat = $reader->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $entryName = $stat['name'];

                if (str_ends_with($entryName, '/')) {
                    continue;
                }

                if (str_starts_with($entryName, 'files/')) {
                    $this->copyFileEntry($reader, $entryName);

                    continue;
                }

                if ($entryName === 'database.sql.gz') {
                    $this->writeGunzippedSql($reader, $entryName);

                    continue;
                }

                if ($entryName === 'backup-meta.json') {
                    continue;
                }

                Log::warning('LocalFlywheelRepackager: skipping unexpected entry', [
                    'entry' => $entryName,
                    'source' => $this->sourceV3ZipPath,
                ]);
            }

            $this->zip->finish();
        } finally {
            $reader->close();
            if (is_resource($this->outputStream)) {
                @fclose($this->outputStream);
            }
            if ($this->sqlTmpPath !== null && is_file($this->sqlTmpPath)) {
                @unlink($this->sqlTmpPath);
                $this->sqlTmpPath = null;
            }
        }

        clearstatcache(true, $this->outputPath);
        $size = (int) filesize($this->outputPath);
        $sha256 = hash_file('sha256', $this->outputPath);
        if ($sha256 === false) {
            throw new RuntimeException("LocalFlywheelRepackager: hash_file failed on {$this->outputPath}");
        }

        return [
            'path' => $this->outputPath,
            'size' => $size,
            'sha256' => $sha256,
            'entries' => $this->entriesAdded,
        ];
    }

    private function copyFileEntry(ZipArchive $reader, string $entryName): void
    {
        $relative = substr($entryName, strlen('files/'));
        if ($relative === '') {
            return;
        }
        $newName = 'app/public/'.$relative;

        $stream = $reader->getStream($entryName);
        if ($stream === false) {
            throw new RuntimeException("LocalFlywheelRepackager: cannot stream entry '{$entryName}'");
        }

        try {
            $this->zip->addFileFromStream(fileName: $newName, stream: $stream);
        } finally {
            fclose($stream);
        }

        $this->entriesAdded++;
    }

    private function writeGunzippedSql(ZipArchive $reader, string $entryName): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sam-sqlgz-');
        if ($tmp === false) {
            throw new RuntimeException('LocalFlywheelRepackager: cannot create tmp for SQL dump');
        }
        $this->sqlTmpPath = $tmp;

        $src = $reader->getStream($entryName);
        if ($src === false) {
            throw new RuntimeException("LocalFlywheelRepackager: cannot stream entry '{$entryName}'");
        }

        $dst = @fopen($tmp, 'wb');
        if ($dst === false) {
            fclose($src);
            throw new RuntimeException("LocalFlywheelRepackager: cannot open tmp '{$tmp}' for write");
        }

        try {
            stream_copy_to_stream($src, $dst);
        } finally {
            fclose($src);
            fclose($dst);
        }

        // Transparent gunzip via PHP's stream wrapper — gives a regular stream
        // that ZipStream can consume with fread()/feof().
        $gunzip = @fopen("compress.zlib://{$tmp}", 'rb');
        if ($gunzip === false) {
            throw new RuntimeException("LocalFlywheelRepackager: cannot open compress.zlib stream over '{$tmp}'");
        }

        try {
            $this->zip->addFileFromStream(fileName: 'app/sql/local.sql', stream: $gunzip);
        } finally {
            fclose($gunzip);
        }

        $this->entriesAdded++;
    }
}
