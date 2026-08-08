<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\V2\Models\BackupSession;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\RetentionService;
use Illuminate\Console\Command;

/**
 * Clear out the failed and cancelled backup rows.
 *
 * A failed backup is not a restore point — it is a record that one was attempted and did not happen.
 * Kept forever they do two kinds of harm: the history on a site's screen fills with rows nobody can
 * do anything with, and a run that died mid-upload leaves objects in the bucket under a prefix that
 * only its own row knows about.
 *
 * That second half is why this does not simply DELETE. Dropping the rows first would strand every
 * partially-written object in storage — paid for, unreachable, and invisible to retention, which
 * only ever looks at rows. So each one goes through RetentionService::purge, which removes the
 * objects under the prefix, the replicas, the sidecar and the used_bytes accounting, and only then
 * the row.
 *
 * Guards, for the same reason the legacy purge has them:
 *
 * - dry-run by default; --apply is required to remove anything,
 * - a locked backup is never touched, whatever its status — a padlock is a person's decision,
 * - a row purge() could not fully clean is KEPT, so a later pass can finish rather than orphaning
 *   what it could not delete,
 * - completed backups are out of scope entirely: this command cannot delete a restore point, which
 *   is what makes it safe to run without reading the fleet first.
 */
class PurgeFailedBackupsCommand extends Command
{
    protected $signature = 'backup:purge-failed '
        .'{--apply : Actually delete; default is a dry-run report} '
        .'{--site= : Restrict to a single site id} '
        .'{--older-than= : Only rows older than N days}';

    protected $description = 'Delete failed and cancelled backup rows and any objects they left behind.';

    public function handle(RetentionService $retention): int
    {
        $apply = (bool) $this->option('apply');

        $query = Backup::query()
            ->whereIn('status', [BackupStatus::Failed, BackupStatus::Cancelled])
            ->where('is_locked', false)
            ->with('site');

        if ($this->option('site') !== null) {
            $query->where('site_id', (int) $this->option('site'));
        }

        if ($this->option('older-than') !== null) {
            $query->where('created_at', '<', now()->subDays((int) $this->option('older-than')));
        }

        $bySite = $query->get()->groupBy('site_id');

        if ($bySite->isEmpty()) {
            $this->info('No failed or cancelled backups to purge.');

            return self::SUCCESS;
        }

        $rows = [];
        $purged = 0;
        $kept = 0;

        foreach ($bySite as $backups) {
            $first = $backups->first();
            $name = $first->relationLoaded('site') && $first->site !== null
                ? (string) $first->site->name
                : 'site #'.$first->site_id;
            $withObjects = $backups->filter(fn (Backup $b) => filled($b->file_path))->count();

            $rows[] = [$name, $backups->count(), $withObjects];

            if (! $apply) {
                $purged += $backups->count();

                continue;
            }

            foreach ($backups as $backup) {
                // A failed row may have no file_path at all — the run died before it wrote anything.
                // purge() handles that (there is nothing to delete), but the session row still has to
                // go, or the site keeps a session pointing at a backup that no longer exists.
                if (! $retention->purge($backup)) {
                    $this->warn("backup #{$backup->id} ({$name}): kept — at least one artifact could not be deleted");
                    $kept++;

                    continue;
                }

                BackupSession::where('backup_id', $backup->id)->delete();
                $purged++;
            }
        }

        $this->table(['Site', 'Failed/cancelled', 'With objects in storage'], $rows);

        $this->line('');
        $this->info(sprintf(
            '%s %d row(s).%s',
            $apply ? 'Purged' : 'Would purge',
            $purged,
            $kept > 0 ? sprintf(' Kept %d whose artifacts could not be removed.', $kept) : '',
        ));

        if (! $apply) {
            $this->line('');
            $this->warn('Dry-run. Nothing was deleted. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
