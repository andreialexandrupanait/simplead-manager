<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Crypto\BackupKeyring;
use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Jobs\BuildPortablePackageJob;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Portable\PortablePackageBuilder;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Aws\S3\Exception\S3Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;
use ZipArchive;

/**
 * The archive is the recovery plan, so the job that produces it has two jobs of its own: never
 * publish one that has not been opened and checked, and never let the collection of them grow
 * without bound.
 *
 * Packages do not deduplicate against each other the way the engine's own objects do — each is a
 * whole separate copy of the site — so unbounded keeping means a second bucket growing as fast as
 * the first.
 */
#[Group('minio')]
#[Group('e2e')]
class PortablePackageJobTest extends TestCase
{
    use InteractsWithLabMinio, MakesV2Sessions, RefreshDatabase;

    private ObjectLayout $layout;

    private StorageDestination $destination;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinio();
        $this->destination = $this->labDestination();
        $this->site = Site::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->cleanupMinio();
        parent::tearDown();
    }

    public function test_a_package_that_fails_verification_is_never_published(): void
    {
        $session = $this->sessionWithBackup();

        // A manifest that promises a database segment which is not valid gzip. The archive builds —
        // the bytes copy fine — and only opening it afterwards reveals the dump is unusable.
        $this->writeBackup($session, ['index.php' => '<?php'], 'this is not gzip at all', rawDatabase: true);

        try {
            (new BuildPortablePackageJob((int) $session->id))->handle();
            $this->fail('a package whose database will not parse must not be reported as ready');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('failed its own verification', $e->getMessage());
        }

        $this->assertFalse(
            $this->objectExists(PortablePackageBuilder::objectKeyFor($session->fresh())),
            'a package that did not pass must not reach storage, where the download button would find it',
        );

        $verification = BackupVerification::query()
            ->where('backup_session_id', $session->id)
            ->where('kind', BackupVerification::KIND_PORTABLE)
            ->firstOrFail();
        $this->assertSame(BackupVerification::STATUS_FAILED, $verification->status);
        $this->assertNull($verification->verified_at);

        $this->assertStringContainsString(
            'failed',
            (string) Backup::find($session->backup_id)?->notes,
            'the failure has to be visible where someone looks for the download',
        );
    }

    public function test_a_good_package_is_published_and_recorded_as_verified(): void
    {
        $session = $this->sessionWithBackup();
        $this->writeBackup($session, ['index.php' => '<?php', 'wp-config.php' => '<?php'], $this->realisticDump("CREATE TABLE `wp_a` (id int);\nINSERT INTO `wp_a` VALUES (1);\n"));

        (new BuildPortablePackageJob((int) $session->id))->handle();

        $key = (string) $session->fresh()->checkpoint['portable_key'];
        $this->assertTrue($this->objectExists($key));

        $verification = BackupVerification::query()
            ->where('backup_session_id', $session->id)
            ->where('kind', BackupVerification::KIND_PORTABLE)
            ->firstOrFail();
        $this->assertSame(BackupVerification::STATUS_PASSED, $verification->status);
        $this->assertNotNull($verification->verified_at);

        // The checks actually looked inside, rather than trusting the build.
        $this->assertTrue((bool) ($verification->checks['outer_zip_consistent'] ?? false));
        $this->assertGreaterThan(0, (int) ($verification->checks['database']['table_count'] ?? 0));
    }

    /**
     * Every restore point keeps its own downloadable package.
     *
     * One per site was kept, so building a new package deleted the previous one —
     * and left `notes` on that older backup still saying "Portable package ready".
     * Every row in the history advertised a download that no longer existed, and
     * pressing it quietly queued a rebuild while the screen said nothing had
     * happened.
     *
     * There is no separate lifetime to manage: the package is written under its
     * own session's object prefix, so retention removes it with the restore point
     * it belongs to.
     */
    public function test_an_older_package_survives_a_newer_build(): void
    {
        $older = $this->sessionWithBackup();
        $this->writeBackup($older, ['index.php' => '<?php'], $this->realisticDump("CREATE TABLE `wp_a` (id int);\n"));
        (new BuildPortablePackageJob((int) $older->id))->handle();

        $olderKey = $older->fresh()->checkpoint['portable_key'] ?? null;
        $this->assertNotNull($olderKey, 'the first package was built');

        $newer = $this->sessionWithBackup();
        $this->writeBackup($newer, ['index.php' => '<?php'], $this->realisticDump("CREATE TABLE `wp_a` (id int);\n"));
        (new BuildPortablePackageJob((int) $newer->id))->handle();

        $this->assertSame(
            $olderKey,
            $older->fresh()->checkpoint['portable_key'] ?? null,
            'the older package is still downloadable',
        );
        $this->assertTrue($this->objectExists($olderKey), 'and still in storage');
    }

    // ── fixture ──────────────────────────────────────────────────────────

    /**
     * A dump shaped the way the plugin actually writes them — header and footer included. The
     * verifier checks for both, and a fixture without them would be testing the fixture.
     */
    private function realisticDump(string $body): string
    {
        return "-- Simplead Backup — consistent logical dump\n"
            ."SET NAMES utf8mb4;\n"
            ."SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n\n"
            .$body
            ."\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    private function sessionWithBackup(): BackupSession
    {
        $session = $this->makeBackupSession($this->site, ['type' => 'full', 'state' => S::Completed]);

        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
        ]);

        // Freeze the prefix the fixture writes to, so the job resolves objects where they actually
        // are rather than recomputing a path from the production template.
        $session->newQuery()->whereKey($session->getKey())->toBase()->update([
            'backup_id' => $backup->id,
            'object_prefix' => $this->testPrefix.'/clients/1/sites/'.$this->site->id.'/backups/'.$session->id,
        ]);

        return $session->fresh();
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeBackup(BackupSession $session, array $files, string $sql, bool $rawDatabase = false): void
    {
        $this->layout = $this->layoutFor($session);
        $cipher = (new BackupKeyring)->forKeyId(BackupKeyring::INSTALLATION_KEY_ID);

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'jobsrc_');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        $chunkKey = $this->layout->files('chunk_0.zip');
        $sealed = (string) tempnam(sys_get_temp_dir(), 'jobsealed_');
        $cipher->encryptFile($zipPath, $sealed);
        $this->minioClient()->putObject(['Bucket' => $this->bucket, 'Key' => $chunkKey, 'SourceFile' => $sealed]);
        @unlink($zipPath);
        @unlink($sealed);

        $dbKey = $this->layout->database('chunk_0.sql.gz');
        $gzPath = (string) tempnam(sys_get_temp_dir(), 'jobdb_');
        file_put_contents($gzPath, $rawDatabase ? $sql : (string) gzencode($sql));
        $sealedDb = (string) tempnam(sys_get_temp_dir(), 'jobdbsealed_');
        $cipher->encryptFile($gzPath, $sealedDb);
        $this->minioClient()->putObject(['Bucket' => $this->bucket, 'Key' => $dbKey, 'SourceFile' => $sealedDb]);
        @unlink($gzPath);
        @unlink($sealedDb);

        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->layout->manifest(),
            'Body' => (string) json_encode([
                'format_version' => 'simplead-backup/2',
                'objects' => [
                    ['kind' => 'files', 'chunk_index' => 0, 'key' => $chunkKey, 'size' => 1, 'sha256' => 'x'],
                    ['kind' => 'database', 'chunk_index' => 0, 'key' => $dbKey, 'size' => 1, 'sha256' => 'y'],
                ],
                'files' => [
                    'included' => array_map(
                        static fn (string $p): array => ['p' => $p, 's' => 1, 'm' => 0, 'sha256' => 'z', 'chunk_index' => 0, 'key' => $chunkKey],
                        array_keys($files),
                    ),
                    'tombstones' => [],
                ],
                'database' => ['name' => 'wp', 'table_count' => 1, 'total_rows' => 1],
                'environment' => ['table_prefix' => 'wp_'],
            ]),
        ]);
    }

    private function layoutFor(BackupSession $session): ObjectLayout
    {
        // The same resolver the job uses, so the fixture and the job cannot disagree about where
        // the objects live.
        return SessionLayoutResolver::for($session);
    }

    private function objectExists(string $key): bool
    {
        try {
            $this->minioClient()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }
}
