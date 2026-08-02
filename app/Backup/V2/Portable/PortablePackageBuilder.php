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
use App\Models\Site;
use App\Services\Backup\BackupZipBuilder;
use Aws\S3\S3Client;
use RuntimeException;
use ZipStream\CompressionMethod;

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
     * @return array{files: int, bytes: int, sha256: string}
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

        // DEFLATE, unlike the staging archives this writer usually produces. This one is kept and
        // handed to a person: a WordPress install is thousands of PHP, CSS and JS files under the
        // media, and on a real site compressing them took the package from 448 MB to 266 MB. The
        // database dump is added STORE'd below, because it is gzip already.
        $builder = new BackupZipBuilder($destination, CompressionMethod::DEFLATE);

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

            $manifest = $this->reader->read($session);
            $builder->addString('backup-meta.json', (string) json_encode(
                $this->meta($session, $manifest, $files),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $builder->addString('RESTAURARE.txt', $this->restoreInstructions($session, $manifest));

            $result = $builder->finish();
        } catch (\Throwable $e) {
            $builder->abort();

            throw $e;
        }

        return ['files' => $files, 'bytes' => (int) $result['size'], 'sha256' => (string) $result['sha256']];
    }

    /**
     * What the archive says about itself.
     *
     * `type` describes the ARCHIVE, not the run that produced it: since format/2 every restore
     * point is complete, so every package is a whole site even when the backup behind it uploaded
     * nothing. Calling it `incremental` here would be true of the run and misleading about the file
     * in your hands. The run's own type is kept alongside, for provenance.
     *
     * `format` is v3-zip because that is genuinely what this is — the same layout the previous
     * engine writes — which lets IntegrityVerifier and BackupBrowserService read it unchanged.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function meta(BackupSession $session, array $manifest, int $files): array
    {
        $site = $session->site instanceof Site ? $session->site : null;
        $env = (array) ($manifest['environment'] ?? []);
        $db = (array) ($manifest['database'] ?? []);

        return [
            'format' => 'v3-zip',
            'type' => 'full',
            'produced_by' => 'simplead-backup',
            'source_backup_type' => (string) $session->type,
            'source_format_version' => (string) ($manifest['format_version'] ?? ''),
            'session_id' => (int) $session->id,
            'backup_id' => $session->backup_id === null ? null : (int) $session->backup_id,

            'site_id' => (int) $session->site_id,
            'site_name' => $site?->name,
            'site_url' => $site?->url,
            'site_domain' => $site?->domain,

            'restore_point_at' => optional($session->completed_at)->toIso8601String()
                ?? (string) ($manifest['completed_at'] ?? ''),
            'packaged_at' => now()->toIso8601String(),

            'wp_version' => $env['wp_version'] ?? null,
            'php_version' => $env['php_version'] ?? null,
            'db_server_version' => $env['db_server_version'] ?? null,
            'db_engine' => $env['db_engine'] ?? null,
            'table_prefix' => $env['table_prefix'] ?? null,
            // Whether the dump came from a single transactional snapshot. A restore from a dump
            // taken without one can contain rows that never coexisted; worth knowing before relying
            // on it, and worth never hiding.
            'consistent_snapshot' => (bool) ($env['consistent_snapshot'] ?? false),

            'files_count' => $files,
            'database_name' => $db['name'] ?? null,
            'database_tables' => (int) ($db['table_count'] ?? 0),
            'database_rows' => (int) ($db['total_rows'] ?? 0),
        ];
    }

    /**
     * The instructions, in the archive, in Romanian.
     *
     * The whole reason this package exists is that the platform might not be there when it is
     * needed. Instructions that live in the manager are instructions you cannot read on the day
     * you need them.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function restoreInstructions(BackupSession $session, array $manifest): string
    {
        $env = (array) ($manifest['environment'] ?? []);
        $db = (array) ($manifest['database'] ?? []);
        $site = $session->site instanceof Site ? $session->site : null;

        $domain = $site !== null ? $site->url : ('site '.$session->site_id);
        $prefix = (string) ($env['table_prefix'] ?? 'wp_');
        $tables = (int) ($db['table_count'] ?? 0);
        $rows = (int) ($db['total_rows'] ?? 0);
        $when = optional($session->completed_at)->format('d.m.Y H:i') ?? '(necunoscut)';

        return <<<TXT
        RESTAURARE MANUALĂ — {$domain}
        Punct de restaurare: {$when}

        Arhiva conține tot ce trebuie ca să repui site-ul în picioare fără SimpleAd Manager:

          files/             toate fișierele site-ului ({$prefix}… în baza de date)
          database.sql.gz    dump complet: {$tables} tabele, {$rows} rânduri
          backup-meta.json   versiuni și cifre, pentru verificare

        PAȘI

        1. Dezarhivează.

               unzip backup.zip -d restaurare

        2. Copiază fișierele în rădăcina site-ului.

               cp -a restaurare/files/. /calea/catre/site/

        3. Importă baza de date într-una GOALĂ.

               gunzip -c restaurare/database.sql.gz | mysql -u UTILIZATOR -p NUME_BAZA

           Dumpul e consistent: toate tabelele au fost citite din același snapshot,
           deci nu conține rânduri care nu au existat împreună.

        4. Ajustează wp-config.php pentru noua bază: DB_NAME, DB_USER, DB_PASSWORD,
           DB_HOST. Prefixul tabelelor este "{$prefix}" — dacă îl schimbi, site-ul
           pornește dar nu-și găsește conținutul.

        5. Dacă domeniul diferă, corectează adresele:

               UPDATE {$prefix}options SET option_value='https://domeniul-nou'
                 WHERE option_name IN ('siteurl','home');

        6. Verifică: pagina principală răspunde, apoi zona de administrare.

        VERIFICARE ÎNAINTE DE A TE BAZA PE EA

               unzip -t backup.zip
               gunzip -t restaurare/database.sql.gz

        Ambele trebuie să treacă fără eroare. Numărul de fișiere din files/ și cifrele
        bazei de date trebuie să corespundă cu backup-meta.json.
        TXT;
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

            $out->addFileFromPath($combined, 'database.sql.gz', CompressionMethod::STORE);
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
