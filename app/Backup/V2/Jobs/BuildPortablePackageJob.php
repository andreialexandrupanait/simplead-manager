<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Portable\PortablePackageBuilder;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\IntegrityVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

            // Opened, parsed and checked BEFORE it is published. If manual restore is the recovery
            // plan, the archive has to be proven good on the day it is made — not on the day it is
            // needed. A package that fails is never uploaded, so the download button can only ever
            // hand over something that passed.
            $verification = $this->verify($session, $local, (string) $result['sha256'], (int) $result['files']);
            if (! $verification->passed()) {
                throw new RuntimeException(
                    "The portable package failed its own verification: {$verification->error}"
                );
            }

            $s3->client()->putObject([
                'Bucket' => $s3->bucket(),
                'Key' => $key,
                'SourceFile' => $local,
                'ContentType' => 'application/zip',
            ]);

            $this->recordOnBackup($session, $key, $result['bytes']);
            $this->pruneOlderPackages($s3, $session);
        } catch (Throwable $e) {
            $this->recordFailure($session, $e->getMessage());

            throw $e;
        } finally {
            @unlink($local);
        }
    }

    /**
     * Run the offline integrity checks over the built archive and record the outcome.
     *
     * Reuses the verifier the previous engine has always used on its own archives — CHECKCONS over
     * every entry's CRC, backup-meta.json parsed, the dump extracted and run through the SQL parser,
     * files/ non-empty. Same layout, same checks; nothing new to get wrong.
     */
    private function verify(BackupSession $session, string $localPath, string $sha256, int $files): BackupVerification
    {
        $outcome = (new IntegrityVerifier)->verifyV3Zip($localPath, $sha256);

        return BackupVerification::create([
            'backup_session_id' => $session->id,
            'kind' => BackupVerification::KIND_PORTABLE,
            'status' => $outcome['ok'] ? BackupVerification::STATUS_PASSED : BackupVerification::STATUS_FAILED,
            'object_count' => $files,
            'sample_size' => $files,
            'checks' => $outcome['checks'],
            'error' => $outcome['ok'] ? null : $outcome['message'],
            'verified_at' => $outcome['ok'] ? now() : null,
        ]);
    }

    /**
     * Drop the site's older packages, newest first, down to the configured count.
     *
     * Packages do not deduplicate against each other the way the engine's own objects do — each one
     * is a whole separate copy of the site. Keeping one per site is the difference between a fixed
     * cost and a second bucket that grows as fast as the first.
     */
    private function pruneOlderPackages(S3ClientFactory $s3, BackupSession $session): void
    {
        $keep = max(1, (int) config('backup_v2.portable.keep_per_site', 1));

        /** @var list<BackupSession> $withPackages */
        $withPackages = BackupSession::query()
            ->where('site_id', $session->site_id)
            ->whereNotNull('checkpoint')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (BackupSession $s): bool => ! empty($s->checkpoint['portable_key'] ?? null))
            ->values()
            ->all();

        foreach (array_slice($withPackages, $keep) as $stale) {
            $staleKey = (string) $stale->checkpoint['portable_key'];

            try {
                $s3->client()->deleteObject(['Bucket' => $s3->bucket(), 'Key' => $staleKey]);
            } catch (Throwable $e) {
                // Leaving a package behind costs storage; failing the build over it would cost the
                // fresh package that is already verified and uploaded.
                Log::warning("BuildPortablePackageJob: could not remove the old package {$staleKey}: {$e->getMessage()}");

                continue;
            }

            $checkpoint = $stale->checkpoint ?? [];
            unset($checkpoint['portable_key'], $checkpoint['portable_bytes'], $checkpoint['portable_built_at']);
            $stale->newQuery()->whereKey($stale->getKey())->toBase()->update([
                'checkpoint' => json_encode($checkpoint),
            ]);
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

    /**
     * Release the uniqueness lock when the job dies, and say so where it can be seen.
     *
     * A PHP fatal kills the worker before Laravel can release the ShouldBeUnique lock, so it sat
     * held for the full uniqueFor hour. During that hour every further attempt — the nightly one,
     * and every press of the Download button — was silently discarded while the UI kept saying it
     * was preparing. That is exactly what happened the first time a real site was tried.
     */
    public function failed(Throwable $e): void
    {
        try {
            Cache::lock('laravel_unique_job:'.static::class.$this->uniqueId())->forceRelease();
        } catch (Throwable $releaseError) {
            Log::warning("BuildPortablePackageJob: could not release its unique lock: {$releaseError->getMessage()}");
        }

        $session = BackupSession::find($this->backupSessionId);
        if ($session instanceof BackupSession) {
            $this->recordFailure($session, $e->getMessage());
        }
    }
}
