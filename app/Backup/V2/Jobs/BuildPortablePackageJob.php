<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Portable\PortablePackageBuilder;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Rebuild a backup into a single archive the owner can download and use.
 *
 * Queued rather than done in the request: replaying a chain means pulling every
 * chunk out of storage, decrypting it and rewriting it into one zip, which for a
 * real site is minutes of work and gigabytes of traffic. A download button that
 * blocks a web worker for that long is a download button that times out.
 *
 * The package lands beside the backup it came from, so retention reclaims it
 * along with the rest of that prefix instead of leaving a pile of one-off
 * downloads nobody accounts for.
 */
class BuildPortablePackageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $backupSessionId)
    {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return 'portable-package-'.$this->backupSessionId;
    }

    public function handle(): void
    {
        $session = BackupSession::findOrFail($this->backupSessionId);
        $site = $session->site;
        if (! $site instanceof Site) {
            throw new RuntimeException("BackupSession {$session->id} has no site.");
        }

        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            throw new RuntimeException("No storage destination is configured for site {$site->id}.");
        }

        $s3 = S3ClientFactory::forDestination($destination);
        $reader = new S3ManifestReader(
            $s3->readClient(),
            $s3->bucket(),
            static fn (BackupSession $member) => SessionLayoutResolver::for($member),
        );

        // Reads the whole chain back out; writes exactly one object at the end.
        $builder = new PortablePackageBuilder($s3->readClient(), $s3->bucket(), $reader);

        // The finished package is the size of the site. sys_get_temp_dir() is a
        // 512 MB tmpfs in production, so it goes on the storage volume with the
        // rest of the intermediates.
        $local = (string) tempnam($builder->workDir(), 'portable_out_');
        $key = PortablePackageBuilder::objectKeyFor($session);

        try {
            $result = $builder->build($session, $local);

            $s3->client()->putObject([
                'Bucket' => $s3->bucket(),
                'Key' => $key,
                'SourceFile' => $local,
                'ContentType' => 'application/zip',
            ]);

            $this->recordOnBackup($session, $key, $result['bytes']);
        } catch (Throwable $e) {
            $this->recordFailure($session, $e->getMessage());

            throw $e;
        } finally {
            @unlink($local);
        }
    }

    private function recordOnBackup(BackupSession $session, string $key, int $bytes): void
    {
        if ($session->backup_id === null) {
            return;
        }

        Backup::query()->whereKey($session->backup_id)->update([
            'notes' => sprintf('Portable package ready (%s).', $this->humanBytes($bytes)),
            'updated_at' => now(),
        ]);

        $session->newQuery()->whereKey($session->getKey())->toBase()->update([
            'checkpoint' => json_encode(array_merge($session->checkpoint ?? [], [
                'portable_key' => $key,
                'portable_bytes' => $bytes,
                'portable_built_at' => now()->toIso8601String(),
            ])),
        ]);
    }

    private function recordFailure(BackupSession $session, string $message): void
    {
        if ($session->backup_id === null) {
            return;
        }

        Backup::query()->whereKey($session->backup_id)->update([
            'notes' => 'Portable package failed: '.\Illuminate\Support\Str::limit($message, 200),
            'updated_at' => now(),
        ]);
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / (1024 ** 3), 1).' GB'
            : round($bytes / (1024 ** 2), 1).' MB';
    }
}
