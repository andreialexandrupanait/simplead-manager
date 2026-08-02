<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Crypto\BackupKeyring;
use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Portable\PortablePackageBuilder;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;
use ZipArchive;

/**
 * A backup you cannot use without the platform that made it is a backup with a
 * dependency nobody agreed to.
 *
 * What the engine stores is right for the engine and useless to a person: file
 * chunks and gzip segments under a prefix, where the site's actual state is not
 * any single object but the result of replaying a chain — latest-wins across
 * every link, minus the tombstones — and every object is encrypted besides.
 * Handed that bucket listing, an owner could not reconstruct their site without
 * reimplementing the resolver.
 *
 * So the package is the same shape the previous engine produced: one zip with
 * `files/` and `database.sql.gz`, openable with any unzip tool.
 */
#[Group('minio')]
#[Group('e2e')]
class PortablePackageTest extends TestCase
{
    use InteractsWithLabMinio, MakesV2Sessions, RefreshDatabase;

    private ObjectLayout $layout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinio();
    }

    protected function tearDown(): void
    {
        $this->cleanupMinio();
        parent::tearDown();
    }

    /**
     * Writes a real backup to MinIO: encrypted file chunk(s), an encrypted DB segment, and a
     * format/2 manifest that names the object holding every file.
     *
     * @param  array<string, string>|list<array<string, string>>  $files  one chunk's files, or a
     *                                                                    list of chunks
     */
    private function writeBackup(BackupSession $session, array $files, string $sql): void
    {
        // Accept a single chunk (the common case) or an explicit list of them.
        $chunks = ($files !== [] && is_array(reset($files))) ? array_values($files) : [$files];

        $this->layout = new ObjectLayout(1, (int) $session->site_id, (int) $session->id, $this->testPrefix.'/clients/{client_id}/sites/{site_id}/backups/{backup_id}');
        $cipher = (new BackupKeyring)->forKeyId(BackupKeyring::INSTALLATION_KEY_ID);

        $fileObjects = [];
        $included = [];

        foreach ($chunks as $index => $chunkFiles) {
            $zipPath = (string) tempnam(sys_get_temp_dir(), 'pkgsrc_');
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($chunkFiles as $path => $contents) {
                $zip->addFromString($path, $contents);
            }
            $zip->close();

            $key = $this->layout->files("chunk_{$index}.zip");
            $sealed = (string) tempnam(sys_get_temp_dir(), 'pkgsealed_');
            $cipher->encryptFile($zipPath, $sealed);
            $this->minioClient()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $sealed,
            ]);
            @unlink($zipPath);
            @unlink($sealed);

            $fileObjects[] = ['kind' => 'files', 'chunk_index' => $index, 'key' => $key, 'size' => 1, 'sha256' => 'x'];
            foreach (array_keys($chunkFiles) as $path) {
                $included[] = ['p' => $path, 's' => 1, 'm' => 0, 'sha256' => 'z', 'chunk_index' => $index, 'key' => $key];
            }
        }

        // database/chunk_0.sql.gz
        $gzPath = (string) tempnam(sys_get_temp_dir(), 'pkgdb_');
        file_put_contents($gzPath, (string) gzencode($sql));
        $sealedDb = (string) tempnam(sys_get_temp_dir(), 'pkgdbsealed_');
        $cipher->encryptFile($gzPath, $sealedDb);
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->database('chunk_0.sql.gz'),
            'SourceFile' => $sealedDb,
        ]);

        $manifest = [
            'format_version' => 'simplead-backup/2',
            'objects' => array_merge($fileObjects, [
                ['kind' => 'database', 'chunk_index' => 0, 'key' => $this->layout->database('chunk_0.sql.gz'), 'size' => 1, 'sha256' => 'y'],
            ]),
            'files' => [
                'included' => $included,
                'tombstones' => [],
            ],
        ];
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->manifest(),
            'Body' => (string) json_encode($manifest),
        ]);

        foreach ([$gzPath, $sealedDb] as $tmp) {
            @unlink($tmp);
        }
    }

    private function builder(): PortablePackageBuilder
    {
        return new PortablePackageBuilder(
            $this->minioClient(),
            $this->bucket,
            new S3ManifestReader($this->minioClient(), $this->bucket, fn () => $this->layout),
        );
    }

    public function test_the_package_opens_as_a_plain_zip_with_files_and_the_database(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Completed]);

        $this->writeBackup(
            $session,
            ['wp-config.php' => '<?php define("DB_NAME", "wp");', 'wp-content/themes/x/style.css' => 'body{}'],
            "CREATE TABLE `wp_options` (id int);\nINSERT INTO `wp_options` VALUES (1);\n",
        );

        $out = (string) tempnam(sys_get_temp_dir(), 'pkg_').'.zip';
        $result = $this->builder()->build($session->fresh(), $out);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out, ZipArchive::CHECKCONS) === true, 'the package must be a valid zip');

        // The shape a person can act on, without this platform.
        $this->assertSame('<?php define("DB_NAME", "wp");', $zip->getFromName('files/wp-config.php'));
        $this->assertSame('body{}', $zip->getFromName('files/wp-content/themes/x/style.css'));

        $dump = $zip->getFromName('database.sql.gz');
        $this->assertNotFalse($dump, 'the package must carry the database');
        $this->assertStringContainsString('CREATE TABLE `wp_options`', (string) gzdecode((string) $dump));

        $zip->close();
        $this->assertSame(2, $result['files']);
        @unlink($out);
    }

    /**
     * The objects in storage are encrypted; the package is not, because its
     * whole purpose is being usable on its own. That is the trade being made
     * deliberately, so it is worth pinning: what leaves the platform is
     * readable, and what sits in the bucket is not.
     */
    public function test_the_stored_objects_were_encrypted_but_the_package_is_readable(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Completed]);
        $this->writeBackup($session, ['secret.txt' => 'ADMIN_PASSWORD=hunter2'], 'SELECT 1;');

        $stored = (string) tempnam(sys_get_temp_dir(), 'stored_');
        $this->minioClient()->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->files('chunk_0.zip'),
            'SaveAs' => $stored,
        ]);
        $this->assertStringNotContainsString('hunter2', (string) file_get_contents($stored));
        @unlink($stored);

        $out = (string) tempnam(sys_get_temp_dir(), 'pkg_').'.zip';
        $this->builder()->build($session->fresh(), $out);

        $zip = new ZipArchive;
        $zip->open($out);
        $this->assertSame('ADMIN_PASSWORD=hunter2', $zip->getFromName('files/secret.txt'));
        $zip->close();
        @unlink($out);
    }

    /**
     * A package quietly short a file is the worst failure this can have, because
     * nobody finds out until they need it.
     */
    public function test_a_manifest_that_lies_about_a_chunk_fails_loudly(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Completed]);
        $this->writeBackup($session, ['real.txt' => 'x'], 'SELECT 1;');

        // Claim a path the chunk does not hold — pointing at a real, readable object, so the lie is
        // only discoverable by actually looking inside it.
        $manifest = json_decode((string) $this->minioClient()->getObject([
            'Bucket' => $this->bucket, 'Key' => $this->layout->manifest(),
        ])['Body'], true);
        $manifest['files']['included'][] = [
            'p' => 'ghost.txt',
            's' => 1,
            'm' => 0,
            'sha256' => 'z',
            'chunk_index' => 0,
            'key' => $this->layout->files('chunk_0.zip'),
        ];
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->manifest(),
            'Body' => (string) json_encode($manifest),
        ]);

        $out = (string) tempnam(sys_get_temp_dir(), 'pkg_').'.zip';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not contain ghost\.txt/');
        $this->builder()->build($session->fresh(), $out);
    }

    /**
     * The bug every other test in this file was too small to see.
     *
     * The builder used to read each entry with getFromName() and hand the string
     * to addFromString(), which makes ZipArchive hold it until close(). Peak
     * memory was therefore the size of the whole site, not of one file — and with
     * fixtures measured in bytes, every assertion above still passed. The first
     * real backup to try it, 450 MB, died on the queue worker's 256 MB limit, and
     * the download button had never worked in production even once.
     *
     * So the property worth pinning is not "the package is correct" — it is "the
     * package is correct without the site fitting in memory".
     */
    public function test_building_a_package_does_not_grow_with_the_size_of_the_site(): void
    {
        // Measured as a SCALING property rather than an absolute ceiling. PHP reports peak usage in
        // whole allocated arenas, which move in large steps and never shrink, so a single number is
        // mostly noise. Doubling the site and seeing the same peak is not.
        $smallSite = $this->measurePeakBuilding(3);
        $largeSite = $this->measurePeakBuilding(6);

        $this->assertLessThan(
            max($smallSite, 4 * 1024 * 1024) * 1.5,
            $largeSite,
            sprintf(
                'peak memory must track one chunk, not the whole site: 3 chunks took %d bytes, 6 took %d',
                $smallSite,
                $largeSite,
            ),
        );
    }

    /**
     * Build a package from $chunkCount chunks of 4 MB each, verify the bytes survive, and return the
     * peak memory the build itself added.
     */
    private function measurePeakBuilding(int $chunkCount): int
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Completed]);

        // Incompressible, so the stored chunks cannot shrink out of the way of what is measured.
        $chunks = [];
        $expected = [];
        for ($c = 0; $c < $chunkCount; $c++) {
            $path = "wp-content/uploads/big_{$c}.bin";
            $bytes = random_bytes(4 * 1024 * 1024);
            $chunks[] = [$path => $bytes];
            $expected[$path] = hash('sha256', $bytes);
        }

        $this->writeBackup($session, $chunks, 'SELECT 1;');

        // The fixture bytes are not part of what the builder costs.
        unset($chunks, $bytes);
        gc_collect_cycles();

        $out = (string) tempnam(sys_get_temp_dir(), 'pkg_').'.zip';

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(true);
        $result = $this->builder()->build($session->fresh(), $out);
        $growth = memory_get_peak_usage(true) - $before;

        // Cheap is only interesting if it is also correct.
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out, ZipArchive::CHECKCONS) === true);
        foreach ($expected as $path => $sha) {
            $this->assertSame(
                $sha,
                hash('sha256', (string) $zip->getFromName('files/'.$path)),
                "the bytes in the package must be the bytes that went in ({$path})",
            );
        }
        $zip->close();
        $this->assertSame(count($expected), $result['files']);
        @unlink($out);

        return $growth;
    }

    /**
     * Every successful build used to leave its whole compressed database behind
     * in the system temp directory, because the only unlink() sat in the failure
     * branch. On a manager backing up a fleet nightly, that is a disk that fills
     * up for no reason anybody can see.
     */
    public function test_a_finished_build_leaves_nothing_behind_in_temp(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Completed]);
        $this->writeBackup($session, ['wp-config.php' => '<?php'], 'SELECT 1;');

        $before = $this->tempArtifacts();

        $out = (string) tempnam(sys_get_temp_dir(), 'pkg_').'.zip';
        $this->builder()->build($session->fresh(), $out);

        $this->assertSame(
            $before,
            $this->tempArtifacts(),
            'the build must clean up its staging directory and database scratch file',
        );

        @unlink($out);
    }

    /**
     * In production /tmp is a 512 MB tmpfs — RAM with a filesystem in front of
     * it. Staging a 473 MB package there is not "on disk"; it is the same memory
     * ceiling one storey up, and a site of any real size overflows it. The
     * intermediates have to land on the storage volume, which is the only
     * writable real disk the container has.
     */
    public function test_intermediates_are_staged_on_the_configured_work_dir_not_tmp(): void
    {
        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Completed]);
        $this->writeBackup($session, ['wp-config.php' => '<?php'], 'SELECT 1;');

        $work = storage_path('app/backup-v2-worktest-'.bin2hex(random_bytes(4)));
        config(['backup_v2.work_dir' => $work]);

        $builder = new PortablePackageBuilder(
            $this->minioClient(),
            $this->bucket,
            new S3ManifestReader($this->minioClient(), $this->bucket, fn () => $this->layout),
        );

        $this->assertSame($work, $builder->workDir());
        $this->assertNotSame(sys_get_temp_dir(), $builder->workDir());
        $this->assertDirectoryExists($work, 'the work directory must be created on demand');

        $out = $work.'/pkg.zip';
        $builder->build($session->fresh(), $out);
        $this->assertFileExists($out);

        // Nothing staged is left behind in the work directory either.
        $this->assertSame([], (array) glob($work.'/portable_stage_*'));

        @unlink($out);
        @rmdir($work);
    }

    /**
     * The archive has to be usable by someone who no longer has this platform, which means it has
     * to say what it is. Instructions that live in the manager are instructions you cannot read on
     * the day you need them.
     */
    public function test_the_package_carries_its_own_metadata_and_instructions(): void
    {
        $site = Site::factory()->create(['url' => 'https://exemplu.ro', 'name' => 'Exemplu']);
        $session = $this->makeBackupSession($site, ['type' => 'incremental', 'state' => S::Completed]);
        $this->writeBackup($session, ['wp-config.php' => '<?php', 'index.php' => '<?php'], "CREATE TABLE `wp_a` (id int);\n");

        $out = (string) tempnam(sys_get_temp_dir(), 'pkg_').'.zip';
        $this->builder()->build($session->fresh(), $out);

        $zip = new ZipArchive;
        $zip->open($out);

        $meta = json_decode((string) $zip->getFromName('backup-meta.json'), true);
        $this->assertIsArray($meta, 'the package must carry backup-meta.json');
        $this->assertSame('https://exemplu.ro', $meta['site_url']);
        $this->assertSame(2, $meta['files_count']);

        // The archive is a whole site even though the run that produced it was an incremental —
        // that is what format/2 buys, and calling it "incremental" here would describe the run
        // while misleading about the file in your hands.
        $this->assertSame('full', $meta['type'], 'the archive is a complete restore point');
        $this->assertSame('incremental', $meta['source_backup_type'], 'the run it came from is kept for provenance');

        $instructions = (string) $zip->getFromName('RESTAURARE.txt');
        $this->assertStringContainsString('exemplu.ro', $instructions);
        $this->assertStringContainsString('database.sql.gz', $instructions);
        $this->assertStringContainsString('siteurl', $instructions, 'a restore onto another domain needs this');

        $zip->close();
        @unlink($out);
    }

    /**
     * @return list<string>
     */
    private function tempArtifacts(): array
    {
        $dir = $this->builder()->workDir();

        $found = array_merge(
            (array) glob($dir.'/portable_stage_*'),
            (array) glob($dir.'/portable_*'),
        );
        sort($found);

        return array_values(array_unique(array_filter($found)));
    }
}
