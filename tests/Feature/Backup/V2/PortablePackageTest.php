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
     * Writes a real backup to MinIO: an encrypted file chunk, an encrypted DB
     * segment, and a manifest that describes them.
     *
     * @param  array<string, string>  $files
     */
    private function writeBackup(BackupSession $session, array $files, string $sql): void
    {
        $this->layout = new ObjectLayout(1, (int) $session->site_id, (int) $session->id, $this->testPrefix.'/clients/{client_id}/sites/{site_id}/backups/{backup_id}');
        $cipher = (new BackupKeyring)->forKeyId(BackupKeyring::INSTALLATION_KEY_ID);

        // files/chunk_0.zip
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'pkgsrc_');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        $sealed = (string) tempnam(sys_get_temp_dir(), 'pkgsealed_');
        $cipher->encryptFile($zipPath, $sealed);
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->files('chunk_0.zip'),
            'SourceFile' => $sealed,
        ]);

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
            'objects' => [
                ['kind' => 'files', 'chunk_index' => 0, 'key' => $this->layout->files('chunk_0.zip'), 'size' => 1, 'sha256' => 'x'],
                ['kind' => 'database', 'chunk_index' => 0, 'key' => $this->layout->database('chunk_0.sql.gz'), 'size' => 1, 'sha256' => 'y'],
            ],
            'files' => [
                'included' => array_map(
                    static fn (string $p): array => ['p' => $p, 's' => 1, 'm' => 0, 'sha256' => 'z', 'chunk_index' => 0],
                    array_keys($files),
                ),
                'tombstones' => [],
            ],
        ];
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->manifest(),
            'Body' => (string) json_encode($manifest),
        ]);

        foreach ([$zipPath, $sealed, $gzPath, $sealedDb] as $tmp) {
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

        // Claim a path the chunk does not hold.
        $manifest = json_decode((string) $this->minioClient()->getObject([
            'Bucket' => $this->bucket, 'Key' => $this->layout->manifest(),
        ])['Body'], true);
        $manifest['files']['included'][] = ['p' => 'ghost.txt', 's' => 1, 'm' => 0, 'sha256' => 'z', 'chunk_index' => 0];
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
}
