<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Exceptions\PreflightFailed;
use App\Backup\V2\Storage\ObjectLayout;
use Aws\S3\S3Client;
use Throwable;

/**
 * Refuse a backup that cannot succeed, before it touches the client's site.
 *
 * The capability probe already asked the host everything needed to know this and
 * then acted on almost none of it: free disk, PHP memory, whether the zip
 * extension the chunker depends on is even loaded, the plugin's own version —
 * all reported, all ignored. The only decision it made was to note a warning
 * when consistent snapshots were unavailable, and carry on anyway.
 *
 * The cost of not checking is not a failed backup. It is a backup that fails
 * *late*: after the plugin has been asked to build chunks, after temp files have
 * been written to a disk that was already nearly full, after the site has spent
 * CPU on work that was never going to be uploaded. The safety principle in the
 * product spec is that the backup stops before the site is affected — which
 * requires stopping before the site is touched.
 *
 * Each failure carries a typed code, so the alert says which precondition failed
 * rather than reporting a generic engine error at some later phase.
 */
class PreflightCheck
{
    /**
     * Older plugin builds are missing endpoints and parameters the runner
     * depends on. Failing here names the version; failing later looks like a
     * broken backup.
     */
    public const MINIMUM_PLUGIN_VERSION = '0.4.0';

    /**
     * @param  array<string, mixed>  $capabilities  raw /capabilities response
     *
     * @throws PreflightFailed
     */
    public function assertHostIsReady(array $capabilities, int $minFreeDiskMb): void
    {
        $this->assertPluginRecentEnough($capabilities);
        $this->assertZipAvailable($capabilities);
        $this->assertTempWritable($capabilities);
        $this->assertEnoughDisk($capabilities, $minFreeDiskMb);
    }

    /**
     * The destination has to accept a write, not merely resolve.
     *
     * Credentials that decrypt, a bucket name that parses and a region that maps
     * prove nothing about whether this backup can be stored. Discovering a
     * revoked key or a policy change at the first chunk means the site has
     * already done the work of producing it.
     *
     * Deliberately a real round trip — put, then delete — under the backup's own
     * prefix. HeadBucket passes on a bucket you can list but not write.
     *
     * @throws PreflightFailed
     */
    public function assertStorageAccepts(S3Client $s3, string $bucket, ObjectLayout $layout): void
    {
        $key = $layout->key('.preflight');

        try {
            $s3->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => 'preflight',
            ]);
        } catch (Throwable $e) {
            throw new PreflightFailed(
                BackupErrorCode::UploadFailed,
                "Storage rejected a test write to {$bucket}: ".$e->getMessage(),
            );
        }

        // Best effort: a bucket that accepts writes but not deletes is unusual
        // and, more to the point, not a reason to refuse the backup. Retention
        // is where that would actually hurt, and it reports its own failures.
        try {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (Throwable) {
        }
    }

    private function assertPluginRecentEnough(array $capabilities): void
    {
        $version = (string) ($capabilities['plugin']['version'] ?? '');

        if ($version === '') {
            throw new PreflightFailed(
                BackupErrorCode::ManifestInvalid,
                'The host did not report a backup plugin version. Is the plugin installed and active?',
            );
        }

        if (version_compare($version, self::MINIMUM_PLUGIN_VERSION, '<')) {
            throw new PreflightFailed(
                BackupErrorCode::ManifestInvalid,
                sprintf(
                    'The site runs backup plugin %s; %s or newer is required. Push the plugin update first.',
                    $version,
                    self::MINIMUM_PLUGIN_VERSION,
                ),
            );
        }
    }

    /**
     * Every file chunk is a zip built by the plugin. Without the extension the
     * chunker fails on the first chunk, after the inventory has already walked
     * the whole filesystem.
     */
    private function assertZipAvailable(array $capabilities): void
    {
        if (($capabilities['extensions']['zip'] ?? null) === false) {
            throw new PreflightFailed(
                BackupErrorCode::EngineError,
                'The host has no zip extension, so file chunks cannot be built. Ask the host to enable ext-zip.',
            );
        }
    }

    /**
     * The plugin reports where its temp directory is and has never checked that
     * it can write there. A directory owned by another user — which is what a
     * root-run wp-cli command leaves behind — makes every dump and every staged
     * restore answer 500 with nothing but a PHP warning in the host's error log.
     */
    private function assertTempWritable(array $capabilities): void
    {
        // Older plugin builds do not report it; absence is not failure.
        if (($capabilities['disk']['temp_writable'] ?? null) === false) {
            $dir = (string) ($capabilities['disk']['temp_dir'] ?? 'the temp directory');

            throw new PreflightFailed(
                BackupErrorCode::DiskFull,
                "The host cannot write to {$dir}. Check its ownership and permissions.",
            );
        }
    }

    /**
     * Free space is reported and was never read. A host that cannot hold one
     * chunk cannot produce a backup, and finding that out at chunk three means
     * having already filled the disk of a live site.
     */
    private function assertEnoughDisk(array $capabilities, int $minFreeDiskMb): void
    {
        $free = $capabilities['disk']['free_bytes'] ?? null;

        // null means the host would not tell us (open_basedir, or a filesystem
        // disk_free_space cannot read). Refusing on that would ground sites for
        // a question we failed to ask, so it is recorded rather than enforced.
        if (! is_int($free) && ! is_float($free)) {
            return;
        }

        $required = $minFreeDiskMb * 1024 * 1024;
        if ((int) $free < $required) {
            throw new PreflightFailed(
                BackupErrorCode::DiskFull,
                sprintf(
                    'The host has %s free and the backup needs at least %s to stage a chunk.',
                    $this->humanBytes((int) $free),
                    $this->humanBytes($required),
                ),
            );
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 1).' GB';
        }

        return round($bytes / (1024 ** 2), 1).' MB';
    }
}
