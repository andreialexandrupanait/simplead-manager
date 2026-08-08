<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\RetentionService;
use Illuminate\Console\Command;

/**
 * Delete the archives written by the old engine, deliberately.
 *
 * They do not expire on their own. Retention prunes per site when that site
 * takes a new backup, so seven sites that have stopped backing up — the four
 * whose database dump outgrew its budget, plus one disconnected and two with no
 * schedule — hold archives that nothing will ever reclaim. Waiting was the plan
 * until the arithmetic was actually done.
 *
 * This is the deliberate alternative, and it is destructive and irreversible.
 * Every guard here exists because of that:
 *
 * - dry-run by default; --apply is required to remove anything,
 * - --keep-unprotected (the default) refuses to touch a site that has no V2
 *   backup to fall back on, because for those sites these archives are the only
 *   restore point in existence,
 * - locked backups are never touched, on either engine,
 * - deletion goes through RetentionService::purge, the one path that removes the
 *   object, its replicas, its sidecar and the used_bytes accounting — a plain row
 *   delete would leave the bytes in the bucket, paid for, with nothing pointing
 *   at them.
 *
 * What is NOT at stake, and is worth saying plainly before anyone runs this: a
 * v3-zip archive is an ordinary zip — `files/` and `database.sql.gz` inside. An
 * archive left in storage stays restorable by hand with any unzip tool and any
 * mysql client, with or without this application.
 */
class PurgeLegacyArchivesCommand extends Command
{
    protected $signature = 'backup:purge-legacy-archives '
        .'{--apply : Actually delete; default is a dry-run report} '
        .'{--include-unprotected : Also purge sites that have NO backup on the new engine} '
        .'{--site= : Restrict to a single site id}';

    protected $description = 'Delete the old engine\'s archives (destructive; dry-run by default).';

    public function handle(RetentionService $retention): int
    {
        $apply = (bool) $this->option('apply');
        $includeUnprotected = (bool) $this->option('include-unprotected');

        $query = Backup::query()
            ->where('engine', BackupEngine::V1)
            ->where('status', BackupStatus::Completed)
            ->whereNotNull('file_path')
            ->where('is_locked', false)
            ->with('site');

        if ($this->option('site') !== null) {
            $query->where('site_id', (int) $this->option('site'));
        }

        $bySite = $query->get()->groupBy('site_id');

        if ($bySite->isEmpty()) {
            $this->info('No legacy archives to purge.');

            return self::SUCCESS;
        }

        // Which sites can afford to lose them: a site with a completed backup on
        // the new engine keeps a restore point after this runs. A site with none
        // does not, and that is the whole reason for the guard.
        $protectedSiteIds = Backup::query()
            ->where('engine', BackupEngine::V2)
            ->where('status', BackupStatus::Completed)
            ->whereIn('site_id', $bySite->keys())
            ->distinct()
            ->pluck('site_id')
            ->all();

        $rows = [];
        $purged = 0;
        $bytes = 0;
        $skipped = 0;

        foreach ($bySite as $siteId => $backups) {
            $hasV2 = in_array((int) $siteId, array_map('intval', $protectedSiteIds), true);
            $name = $backups->first()->site->name;
            $siteBytes = (int) $backups->sum('file_size');

            if (! $hasV2 && ! $includeUnprotected) {
                $rows[] = [$name, $backups->count(), $this->gb($siteBytes), 'KEPT — no backup on the new engine'];
                $skipped += $backups->count();

                continue;
            }

            $rows[] = [
                $name,
                $backups->count(),
                $this->gb($siteBytes),
                $hasV2 ? 'purge' : 'purge (UNPROTECTED)',
            ];

            if (! $apply) {
                $purged += $backups->count();
                $bytes += $siteBytes;

                continue;
            }

            foreach ($backups as $backup) {
                if ($retention->purge($backup)) {
                    $purged++;
                    $bytes += (int) $backup->file_size;

                    continue;
                }

                // purge() keeps the row when an artifact could not be removed, so a
                // later pass can finish rather than orphaning the objects.
                $this->warn("backup #{$backup->id} ({$name}): kept — at least one artifact could not be deleted");
            }
        }

        $this->table(['Site', 'Archives', 'Size', 'Action'], $rows);

        $this->line('');
        $this->info(sprintf(
            '%s %d archive(s), %s.%s',
            $apply ? 'Purged' : 'Would purge',
            $purged,
            $this->gb($bytes),
            $skipped > 0 ? sprintf(' Kept %d on sites with no backup on the new engine.', $skipped) : '',
        ));

        if (! $apply) {
            $this->line('');
            $this->warn('Dry-run. Nothing was deleted. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    private function gb(int $bytes): string
    {
        return number_format($bytes / 1073741824, 1).' GB';
    }
}
