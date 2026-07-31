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
 * Built by streaming: entries are copied chunk by chunk and the database
 * segments appended one at a time, so peak memory is one chunk rather than one
 * backup.
 */
class PortablePackageBuilder
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly ManifestReader $reader,
        private readonly BackupKeyring $keyring = new BackupKeyring,
    ) {}

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

        $files = 0;
        foreach ($wantedByKey as $key => $paths) {
            $files += $this->copyEntries($key, $paths, $zip);
        }

        $this->appendDatabase($session, $zip);

        $zip->close();

        return ['files' => $files, 'bytes' => (int) filesize($destination)];
    }

    /**
     * @param  list<string>  $paths
     */
    private function copyEntries(string $key, array $paths, ZipArchive $out): int
    {
        $local = $this->pull($key);

        try {
            $chunk = new ZipArchive;
            if ($chunk->open($local) !== true) {
                throw new RuntimeException("Backup chunk {$key} could not be opened.");
            }

            $copied = 0;
            foreach ($paths as $path) {
                $bytes = $chunk->getFromName($path);
                if ($bytes === false) {
                    // The manifest says this chunk holds this path. If it does
                    // not, the package would be quietly short a file — which is
                    // exactly the class of failure a portable backup must not
                    // have, because nobody finds out until they need it.
                    throw new RuntimeException("Backup chunk {$key} does not contain {$path}.");
                }

                $out->addFromString('files/'.$path, $bytes);
                $copied++;
            }
            $chunk->close();

            return $copied;
        } finally {
            @unlink($local);
        }
    }

    /**
     * The database is always the target backup's own dump, never reassembled
     * across the chain: every restore point carries a complete, consistent one.
     */
    private function appendDatabase(BackupSession $session, ZipArchive $out): void
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
        $combined = (string) tempnam(sys_get_temp_dir(), 'portable_db_');
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
            @unlink($combined);

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
        $path = (string) tempnam(sys_get_temp_dir(), 'portable_');
        $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $path]);

        if (! ObjectCipher::isEncrypted($path)) {
            return $path;
        }

        $plain = (string) tempnam(sys_get_temp_dir(), 'portable_plain_');
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
