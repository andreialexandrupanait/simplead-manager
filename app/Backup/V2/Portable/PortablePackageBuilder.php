<?php

declare(strict_types=1);

namespace App\Backup\V2\Portable;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Crypto\BackupKeyring;
use App\Backup\V2\Crypto\ObjectCipher;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Storage\WorkDir;
use App\Services\Backup\BackupZipBuilder;
use Aws\S3\S3Client;
use RuntimeException;

/**
 * Rebuild a backup into one archive a person can actually use.
 *
 * What the engine stores is right for the engine and useless to a human: file
 * chunks and gzip database segments under a prefix, where the current state of
 * the site is not any single object but the result of replaying a chain —
 * latest-wins across every link, minus the tombstones. Handed that bucket
 * listing, an owner could not reconstruct their site without reimplementing the
 * resolver, and the objects are encrypted besides.
 *
 * A backup you cannot use without the platform that made it is a backup with a
 * dependency nobody agreed to. This produces the same shape the previous engine
 * did — `files/` plus `database.sql.gz` in one zip — so it opens with any
 * unzip tool and the dump imports with any mysql client.
 *
 * Written sequentially, through BackupZipBuilder — the same streaming writer the previous engine
 * consolidates its chunk zips with. Entries are piped straight from each downloaded chunk into the
 * output, so nothing larger than one chunk (plus the compressed dump, while it is joined) ever
 * exists at once, and peak memory is a pair of copy buffers regardless of how big the site is.
 *
 * Two earlier shapes of this are worth remembering, because both looked fine in the lab:
 *
 *  - addFromString() read each file into a PHP string and made ZipArchive hold it until close(), so
 *    the true peak was the whole backup. The first real site to try it (450 MB) died on the 256 MB
 *    worker limit, and the download button had never once worked in production.
 *  - staging every file to disk first fixed the memory but needed twice the site in scratch space,
 *    and "disk" in this container means a 512 MB tmpfs unless you ask for the storage volume.
 *
 * Streaming removes the choice: there is no staging tree to size, and no scratch directory to clean
 * up after.
 */
class PortablePackageBuilder
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly ManifestReader $reader,
        private readonly BackupKeyring $keyring = new BackupKeyring,
        private readonly ?string $workDir = null,
    ) {}

    /**
     * The directory big intermediate files are written to. Real disk, not tmpfs.
     */
    public function workDir(): string
    {
        if ($this->workDir === null) {
            return WorkDir::path();
        }

        if (! is_dir($this->workDir) && ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException("Could not create the backup work directory {$this->workDir}.");
        }

        return $this->workDir;
    }

    /**
     * Materialise $session (replaying its chain) into a single zip at $destination.
     *
     * @return array{files: int, bytes: int}
     */
    public function build(BackupSession $session, string $destination): array
    {
        // One manifest for a format/2 backup; the chain replay only for older ones.
        $state = (new ChainResolver)->stateFor($session, $this->reader);

        // Group the wanted paths by the object that holds them, so each chunk is
        // pulled from storage exactly once no matter how many of its files
        // survived into the final state.
        $wantedByKey = [];
        foreach ($state as $path => $entry) {
            $key = (string) ($entry['key'] ?? '');
            if ($key === '') {
                throw new RuntimeException("The manifest names no object for {$path}.");
            }
            $this->assertContainedPath((string) $path, $key);
            $wantedByKey[$key][] = (string) $path;
        }

        $builder = new BackupZipBuilder($destination);

        try {
            $files = 0;
            foreach ($wantedByKey as $key => $paths) {
                $local = $this->pull($key);
                try {
                    $files += $builder->addEntriesFromZip($local, 'files', $paths);
                } finally {
                    @unlink($local);
                }
            }

            // Counted separately: `files` means the site's files, not "entries in the archive".
            $this->appendDatabase($session, $builder);
            $result = $builder->finish();
        } catch (\Throwable $e) {
            $builder->abort();

            throw $e;
        }

        return ['files' => $files, 'bytes' => (int) $result['size'], 'sha256' => (string) $result['sha256']];
    }

    /**
     * Refuse a manifest path that would climb out of the package.
     *
     * These paths come from archives this engine wrote itself, so this should never fire — but
     * "should never" is not a reason to pass an unchecked path through, and the check costs nothing.
     * BackupZipBuilder screens entry names too; failing here just names the real problem instead of
     * reporting the file as mysteriously absent from its chunk.
     */
    private function assertContainedPath(string $path, string $key): void
    {
        if (str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new RuntimeException("Backup chunk {$key} names an unsafe path: {$path}.");
        }
    }

    /**
     * The database is always the target backup's own dump, never reassembled
     * across the chain: every restore point carries a complete, consistent one.
     */
    private function appendDatabase(BackupSession $session, BackupZipBuilder $out): int
    {
        $manifest = $this->reader->read($session);
        $segments = array_values(array_filter(
            (array) ($manifest['objects'] ?? []),
            static fn (array $o): bool => ($o['kind'] ?? null) === 'database',
        ));

        if ($segments === []) {
            return 0;
        }

        usort($segments, static fn (array $a, array $b): int => ($a['chunk_index'] ?? 0) <=> ($b['chunk_index'] ?? 0));

        // Concatenated gzip members are themselves valid gzip, so the segments append directly and
        // `gunzip` yields the whole dump in order. The joined file is the only thing that has to
        // exist at once, and a compressed dump is a small fraction of a site.
        $combined = WorkDir::temp('portable_db_');
        $handle = fopen($combined, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$combined} to assemble the database dump.");
        }

        try {
            foreach ($segments as $segment) {
                $local = $this->pull((string) $segment['key']);
                $in = fopen($local, 'rb');
                if ($in === false) {
                    @unlink($local);
                    throw new RuntimeException('Could not read a downloaded database segment.');
                }
                stream_copy_to_stream($in, $handle);
                fclose($in);
                @unlink($local);
            }
            fclose($handle);

            $out->addFileFromPath($combined, 'database.sql.gz');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($combined);
        }

        return 1;
    }

    /**
     * Pull an object to a local file, decrypting it if it is sealed.
     *
     * The key id comes from the object's own header, so a chain that spans a key
     * rotation — or the day encryption was switched on — rebuilds without anyone
     * having to remember which links are which.
     */
    private function pull(string $key): string
    {
        $path = (string) tempnam($this->workDir(), 'portable_');
        $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $path]);

        if (! ObjectCipher::isEncrypted($path)) {
            return $path;
        }

        $plain = (string) tempnam($this->workDir(), 'portable_plain_');
        $this->keyring
            ->forKeyId((string) ObjectCipher::keyIdOf($path))
            ->decryptFile($path, $plain);
        @unlink($path);

        return $plain;
    }

    /**
     * Where the package is stored once built — beside the backup it came from,
     * so retention removes it with the rest of that prefix rather than leaving
     * an ever-growing pile of one-off downloads nobody accounts for.
     */
    public static function objectKeyFor(BackupSession $session): string
    {
        return SessionLayoutResolver::for($session)->key('portable/backup.zip');
    }
}
