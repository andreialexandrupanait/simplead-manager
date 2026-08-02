<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Exceptions\PreflightFailed;

/**
 * Refuse a restore the client's server does not have room for — before anything is transferred.
 *
 * Nothing checked this. Not `restore/prepare`, which accepts a plan without looking at the disk,
 * and not the runner, even though the V1 engine it replaced had exactly this guard. A restore that
 * runs the host out of space does it in the worst possible place: half way through the swap, with
 * the site in maintenance and the rollback itself needing room to move files back.
 *
 * A restore wants space in two places, and they are not always the same filesystem:
 *
 *   temp   the chunks the manager pushes, all of them, before the apply begins;
 *   site   the trash — every displaced live file, which IS the rollback — plus one chunk being
 *          extracted at a time.
 *
 * Sizes come from the manifest, so this is arithmetic on known quantities rather than a guess. The
 * figures are logical (uncompressed) sizes, which for the chunks is the conservative direction: a
 * zip on disk is never larger than what it holds.
 *
 * There is deliberately no "force anyway" button. A refusal that can be clicked past will be
 * clicked past on exactly the day it should not have been, and the price here is a site stopped
 * half way through being changed.
 */
final class RestoreDiskPreflight
{
    /**
     * Head-room over the computed requirement.
     *
     * `disk_free_space` reports the filesystem, which is not the same as what this account is
     * allowed to use: quotas, reservations and whatever else is running on a shared host are all
     * invisible to it. Twenty percent is not a calculation, it is an admission that the number we
     * are given is optimistic.
     */
    public const DEFAULT_MARGIN = 0.20;

    public function __construct(
        private readonly float $margin = self::DEFAULT_MARGIN,
    ) {}

    /**
     * @param  array<string, mixed>  $capabilities  raw /capabilities response from the host
     * @return array<string, mixed> what was checked, for the restore's checkpoint
     *
     * @throws PreflightFailed
     */
    public function assertRoomFor(RestorePlan $plan, array $capabilities): array
    {
        $disk = (array) ($capabilities['disk'] ?? []);

        $tempFree = $this->bytes($disk['free_bytes'] ?? null);
        $siteFree = $this->bytes($disk['site_free_bytes'] ?? null);
        $sameFilesystem = $disk['same_filesystem'] ?? null;

        // The chunks are pushed in full before the apply starts; the site holds the trash plus one
        // chunk of working space.
        $tempNeeded = $this->withMargin($plan->fileBytes);
        $siteNeeded = $this->withMargin($plan->fileBytes + $plan->largestChunkBytes);

        $report = [
            'file_bytes' => $plan->fileBytes,
            'largest_chunk_bytes' => $plan->largestChunkBytes,
            'temp_required_bytes' => $tempNeeded,
            'site_required_bytes' => $siteNeeded,
            'temp_free_bytes' => $tempFree,
            'site_free_bytes' => $siteFree,
            'same_filesystem' => $sameFilesystem,
            'margin' => $this->margin,
        ];

        // A host that will not say how much room it has is not a reason to ground its restores —
        // open_basedir and some container filesystems simply refuse the question. Recorded, not
        // enforced, exactly as the backup preflight treats the same silence.
        if ($tempFree === null && $siteFree === null) {
            return $report + ['checked' => false, 'reason' => 'the host did not report free disk space'];
        }

        // One pool serves both demands, so they add up against a single figure. When the host is
        // too old to say — `same_filesystem` absent — assume they share, which is the conservative
        // reading: it asks for more room, never less.
        if ($sameFilesystem === true || $sameFilesystem === null || $siteFree === null) {
            $free = $siteFree ?? $tempFree; // one of the two is non-null by the guard above
            $needed = $tempNeeded + $siteNeeded;
            $report['combined_required_bytes'] = $needed;

            if ($free < $needed) {
                throw $this->refuse('the host', $free, $needed, (string) ($disk['temp_dir'] ?? ''));
            }

            return $report + ['checked' => true];
        }

        if ($tempFree !== null && $tempFree < $tempNeeded) {
            throw $this->refuse('the temp filesystem', $tempFree, $tempNeeded, (string) ($disk['temp_dir'] ?? ''));
        }
        if ($siteFree < $siteNeeded) {
            throw $this->refuse('the site filesystem', $siteFree, $siteNeeded, (string) ($disk['site_dir'] ?? ''));
        }

        return $report + ['checked' => true];
    }

    private function refuse(string $where, int $free, int $needed, string $path): PreflightFailed
    {
        return new PreflightFailed(
            BackupErrorCode::DiskFull,
            sprintf(
                'Not enough disk space to restore: %s%s has %s free and this restore needs %s '
                .'(including a %d%% margin). Nothing was transferred.',
                $where,
                $path === '' ? '' : " ({$path})",
                $this->humanBytes($free),
                $this->humanBytes($needed),
                (int) round($this->margin * 100),
            ),
        );
    }

    private function withMargin(int $bytes): int
    {
        return (int) ceil($bytes * (1 + $this->margin));
    }

    private function bytes(mixed $value): ?int
    {
        return (is_int($value) || is_float($value)) ? (int) $value : null;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 1).' GB';
        }

        return round($bytes / (1024 ** 2), 1).' MB';
    }
}
