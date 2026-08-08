<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\V2\Models\BackupSession;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Console\Command;

/**
 * Delete objects in the bucket that no backup points at any more.
 *
 * Retention removes a backup's objects when it removes the backup. Everything here got past that:
 * a run that died mid-upload and was later purged as a failure, and the old engine's per-domain
 * `.zip` archives whose rows are gone. Nothing in the product can reach these bytes — they are
 * simply paid for, month after month.
 *
 * The rule is inverted on purpose. Rather than listing what to delete, this keeps only what is
 * still referenced by a live session prefix and treats the rest as reclaimable — so a new artifact
 * kind cannot be missed by a pattern nobody remembered to update.
 *
 * Except for two roots that are NOT the fleet's backups and must survive a fleet cleanup:
 *
 *   application-backups/  — this manager's own archives
 *   database-dumps/       — this manager's own Postgres dumps
 *
 * They are the manager's disaster recovery. They live in the same bucket because that is where
 * off-site storage is configured, and a command that swept the bucket by "is it referenced by a
 * backup row" would take both — which is how a cleanup becomes the incident it was meant to prevent.
 *
 * Dry-run by default, and it reports what it would keep as well as what it would remove: the
 * interesting number when deleting from a bucket is always the one you did not expect to see.
 */
class PurgeOrphanObjectsCommand extends Command
{
    /** Roots that belong to the manager itself, not to any site's backups. Never swept. */
    private const PROTECTED_ROOTS = [
        'application-backups/',
        'database-dumps/',
    ];

    protected $signature = 'backup:purge-orphan-objects '
        .'{--apply : Actually delete; default is a dry-run report} '
        .'{--destination= : Storage destination id (default: every active one)}';

    protected $description = 'Delete bucket objects that no backup references (destructive; dry-run by default).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $destinations = StorageDestination::query()
            ->when($this->option('destination') !== null, fn ($q) => $q->whereKey((int) $this->option('destination')))
            ->when($this->option('destination') === null, fn ($q) => $q->where('is_active', true))
            ->get();

        if ($destinations->isEmpty()) {
            $this->error('No storage destination to sweep.');

            return self::FAILURE;
        }

        // Every prefix a surviving backup still occupies. Read from the session rather than
        // recomputed from ids: a backup's objects live wherever they were first written, and
        // recomputing the prefix is how a healthy backup gets reported as missing.
        $livePrefixes = BackupSession::query()
            ->whereNotNull('object_prefix')
            ->whereHas('backup')
            ->pluck('object_prefix')
            ->unique()
            ->values()
            ->all();

        if ($livePrefixes === []) {
            $this->error('No live backup prefixes found — refusing to sweep a bucket with nothing to protect.');

            return self::FAILURE;
        }

        $this->line(sprintf('Protecting %d live backup prefix(es).', count($livePrefixes)));

        foreach ($destinations as $destination) {
            $this->sweep($destination, $livePrefixes, $apply);
        }

        if (! $apply) {
            $this->line('');
            $this->warn('Dry-run. Nothing was deleted. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    /** @param list<string> $livePrefixes */
    private function sweep(StorageDestination $destination, array $livePrefixes, bool $apply): void
    {
        $this->line('');
        $this->info("Destination #{$destination->id} — {$destination->name}");

        try {
            $driver = StorageFactory::make($destination);
            $objects = $driver->listRecursive('');
        } catch (\Throwable $e) {
            $this->error("  unreadable: {$e->getMessage()}");

            return;
        }

        $orphans = [];
        $keptBytes = 0;
        $protectedBytes = 0;

        foreach ($objects as $object) {
            $key = (string) $object['path'];
            $size = (int) $object['size'];

            if ($key === '') {
                continue;
            }

            foreach (self::PROTECTED_ROOTS as $root) {
                if (str_starts_with($key, $root)) {
                    $protectedBytes += $size;

                    continue 2;
                }
            }

            foreach ($livePrefixes as $prefix) {
                if (str_starts_with($key, $prefix.'/')) {
                    $keptBytes += $size;

                    continue 2;
                }
            }

            $orphans[] = ['key' => $key, 'size' => $size];
        }

        $orphanBytes = array_sum(array_column($orphans, 'size'));

        $this->table(
            ['Referenced by a backup', 'The manager\'s own', 'Unreferenced'],
            [[
                $this->gb($keptBytes),
                $this->gb($protectedBytes),
                sprintf('%s in %d object(s)', $this->gb($orphanBytes), count($orphans)),
            ]],
        );

        if ($orphans === []) {
            $this->line('  Nothing to reclaim.');

            return;
        }

        if (! $apply) {
            foreach (array_slice($orphans, 0, 10) as $orphan) {
                $this->line('  would delete  '.$orphan['key']);
            }
            if (count($orphans) > 10) {
                $this->line(sprintf('  … and %d more', count($orphans) - 10));
            }

            return;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($orphans as $orphan) {
            try {
                $driver->delete($orphan['key']);
                $deleted++;
            } catch (\Throwable $e) {
                $this->warn("  could not delete {$orphan['key']}: {$e->getMessage()}");
                $failed++;
            }
        }

        // used_bytes counted these; it must stop counting them, or the quota on this destination
        // stays wrong in the direction that silently blocks backups.
        $destination->decrement('used_bytes', min((int) $destination->used_bytes, $orphanBytes));

        $this->info(sprintf(
            '  Reclaimed %s across %d object(s).%s',
            $this->gb($orphanBytes),
            $deleted,
            $failed > 0 ? sprintf(' %d could not be deleted.', $failed) : '',
        ));
    }

    private function gb(int $bytes): string
    {
        return number_format($bytes / 1073741824, 2).' GB';
    }
}
