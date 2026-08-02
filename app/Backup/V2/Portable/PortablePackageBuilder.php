<?php

declare(strict_types=1);

namespace App\Backup\V2\Portable;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Crypto\BackupKeyring;
use App\Backup\V2\Crypto\ObjectCipher;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\SessionLayoutResolver;
use Aws\S3\S3Client;
use RuntimeException;
use ZipArchive;

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
 * Built through disk, not memory: entries are staged out of each chunk into a
 * scratch directory and added to the package by path, and the database segments
 * are concatenated into a file first. ZipArchive only reads those files when it
 * closes, so peak memory stays flat no matter how big the site is.
 *
 * It used to use addFromString(), which reads each file into a PHP string and
 * makes ZipArchive hold it until close() — so the true peak was the whole
 * backup, not one chunk as the comment here used to claim. The first real site
 * to try it (450 MB) died on the 256 MB worker limit, and the fixture-sized lab
 * tests could never have caught it.
 *
 * "On disk" has to mean the storage volume, not sys_get_temp_dir(): in
 * production /tmp is a 512 MB tmpfs, so staging there would only have swapped
 * one memory ceiling for a slightly higher one.
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
        $dir = $this->workDir ?? (string) config('backup_v2.work_dir', sys_get_temp_dir());

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the backup work directory {$dir}.");
        }

        return $dir;
    }

    /**
     * Materialise $session (replaying its chain) into a single zip at $destination.
     *
     * @return array{files: int, bytes: int}
     */
    public function build(BackupSession $session, string $destination): array
    {
        $chain = (new ChainResolver)->resolveChain($session);
        $state = (new ChainResolver)->materialize($chain, $this->reader);

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the portable package at {$destination}.");
        }

        // Group the wanted paths by the object that holds them, so each chunk is
        // pulled from storage exactly once no matter how many of its files
        // survived into the final state.
        $wantedByKey = [];
        foreach ($state as $path => $entry) {
            $key = (string) ($entry['key'] ?? '');
            if ($key !== '') {
                $wantedByKey[$key][] = $path;
            }
        }

        // Everything the package is built from lives here until close() has read
        // it, then the whole tree goes. Bounded by the size of the site, on the
        // manager's disk — never on the client's.
        $stage = $this->makeStagingDir();

        try {
            $files = 0;
            foreach ($wantedByKey as $key => $paths) {
                $files += $this->copyEntries($key, $paths, $zip, $stage);
            }

            $this->appendDatabase($session, $zip, $stage);

            // close() is where ZipArchive actually reads every staged file and
            // writes the archive, so nothing may be deleted before it returns.
            if ($zip->close() !== true) {
                throw new RuntimeException("Could not write the portable package at {$destination}.");
            }
        } catch (\Throwable $e) {
            @$zip->close();
            $this->removeTree($stage);

            throw $e;
        }

        $this->removeTree($stage);

        return ['files' => $files, 'bytes' => (int) filesize($destination)];
    }

    /**
     * @param  list<string>  $paths
     */
    private function copyEntries(string $key, array $paths, ZipArchive $out, string $stage): int
    {
        $local = $this->pull($key);

        try {
            $chunk = new ZipArchive;
            if ($chunk->open($local) !== true) {
                throw new RuntimeException("Backup chunk {$key} could not be opened.");
            }

            foreach ($paths as $path) {
                $this->assertContainedPath($path, $key);
            }

            // One call, not one per file: extractTo writes straight to disk, so
            // no entry is ever held whole in memory.
            $chunk->extractTo($stage.'/files', $paths);
            $chunk->close();

            $copied = 0;
            foreach ($paths as $path) {
                $staged = $stage.'/files/'.$path;
                if (! is_file($staged)) {
                    // The manifest says this chunk holds this path. If it does
                    // not, the package would be quietly short a file — which is
                    // exactly the class of failure a portable backup must not
                    // have, because nobody finds out until they need it.
                    throw new RuntimeException("Backup chunk {$key} does not contain {$path}.");
                }

                $out->addFile($staged, 'files/'.$path);
                $copied++;
            }

            return $copied;
        } finally {
            @unlink($local);
        }
    }

    /**
     * Refuse a manifest path that would climb out of the staging directory.
     *
     * These paths come from archives this engine wrote itself, so this should
     * never fire — but "should never" is not a reason to hand an unchecked path
     * to extractTo, and the check costs nothing.
     */
    private function assertContainedPath(string $path, string $key): void
    {
        if (str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new RuntimeException("Backup chunk {$key} names an unsafe path: {$path}.");
        }
    }

    private function makeStagingDir(): string
    {
        $dir = $this->workDir().'/portable_stage_'.bin2hex(random_bytes(8));

        // Site files and the database dump get separate subtrees: a WordPress root
        // is perfectly entitled to contain a file called database.sql.gz, and it
        // must not be able to overwrite the dump we are assembling.
        foreach ([$dir.'/files', $dir.'/db'] as $sub) {
            if (! mkdir($sub, 0700, true) && ! is_dir($sub)) {
                throw new RuntimeException("Could not create the staging directory {$sub}.");
            }
        }

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * The database is always the target backup's own dump, never reassembled
     * across the chain: every restore point carries a complete, consistent one.
     */
    private function appendDatabase(BackupSession $session, ZipArchive $out, string $stage): void
    {
        $manifest = $this->reader->read($session);
        $segments = array_values(array_filter(
            (array) ($manifest['objects'] ?? []),
            static fn (array $o): bool => ($o['kind'] ?? null) === 'database',
        ));

        if ($segments === []) {
            return;
        }

        usort($segments, static fn (array $a, array $b): int => ($a['chunk_index'] ?? 0) <=> ($b['chunk_index'] ?? 0));

        // Concatenated gzip members are themselves valid gzip, so the segments
        // append directly and `gunzip` yields the whole dump in order.
        //
        // It goes in the staging directory rather than a bare tempnam() so that
        // the caller's cleanup reclaims it. Previously it was only removed when
        // the build threw: every SUCCESSFUL package left its whole compressed
        // database behind in the system temp directory, forever.
        $combined = $stage.'/db/database.sql.gz';
        $handle = fopen($combined, 'wb');

        try {
            foreach ($segments as $segment) {
                $local = $this->pull((string) $segment['key']);
                $in = fopen($local, 'rb');
                stream_copy_to_stream($in, $handle);
                fclose($in);
                @unlink($local);
            }
            fclose($handle);

            $out->addFile($combined, 'database.sql.gz');
            // ZipArchive reads the file at close(), so it has to still exist.
            $out->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);
        } catch (\Throwable $e) {
            @fclose($handle);

            throw $e;
        }
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
