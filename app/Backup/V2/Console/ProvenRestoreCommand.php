<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\ProvenRestore\ProvenRestoreService;
use App\Models\Site;
use Closure;
use Illuminate\Console\Command;

/**
 * Scheduled PROVEN RESTORE (App\Backup\V2\ProvenRestore\ProvenRestoreService): restore each
 * opted-in site's latest valid V2 backup into an isolated sandbox and health-check it,
 * appending a `proven_restores` row per run.
 *
 * INERT without config('backup_v2.proven_restore_enabled') — with the default flag off the
 * command does nothing (no restore, no DB writes), safe to register/schedule in production.
 *
 * The restore TRANSPORT (sandbox plugin/S3 clients + health probe) is environment-specific and
 * is injected via the container binding 'backup_v2.proven_restore.restorer'
 * (a Closure(RestoreSession, BackupSession): array{ok:bool, checks:array, error?:?string}).
 * When nothing is bound the command reports the eligible sites and exits cleanly rather than
 * fabricating a result — the sandbox wiring shares the same production-resolution TODO as the
 * rest of V2. The service itself is proven end-to-end by the lab test (real MinIO + the
 * in-process plugin restore engine writing a real passed row).
 */
class ProvenRestoreCommand extends Command
{
    protected $signature = 'backup:v2-proven-restore '
        .'{--site= : Restrict to a single site id} '
        .'{--json : Emit a machine-readable JSON report}';

    protected $description = 'Restore each opted-in site\'s latest valid V2 backup into a sandbox and record the outcome.';

    public function handle(): int
    {
        if (! (bool) config('backup_v2.proven_restore_enabled', false)) {
            $this->warn('backup_v2.proven_restore_enabled is false — proven-restore is inert (nothing restored).');

            return self::SUCCESS;
        }

        $restorer = $this->resolveRestorer();
        $service = new ProvenRestoreService;

        $results = [];
        foreach ($this->sites() as $site) {
            $backup = $service->latestValidBackup((int) $site->id);
            if (! $backup instanceof BackupSession) {
                $results[] = ['site_id' => $site->id, 'status' => 'skipped', 'reason' => 'no_valid_backup'];

                continue;
            }
            if ($restorer === null) {
                $results[] = ['site_id' => $site->id, 'status' => 'skipped', 'reason' => 'no_sandbox_restorer_bound', 'backup_session_id' => $backup->id];

                continue;
            }

            $proven = $service->run($site, $backup, $restorer);
            $results[] = ['site_id' => $site->id, 'status' => $proven->status, 'proven_restore_id' => $proven->id];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($results === []) {
            $this->info('No sites opted into proven-restore.');

            return self::SUCCESS;
        }
        foreach ($results as $r) {
            $this->line("site #{$r['site_id']}: {$r['status']}".(isset($r['reason']) ? " ({$r['reason']})" : ''));
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Site>
     */
    private function sites(): \Illuminate\Support\Collection
    {
        $query = Site::query()->where('proven_restore_enabled', true);
        if ($this->option('site') !== null) {
            $query->whereKey((int) $this->option('site'));
        }

        return $query->get();
    }

    /**
     * @return Closure(RestoreSession, BackupSession): array{ok:bool, checks:array<string,mixed>, error?:string|null}|null
     */
    private function resolveRestorer(): ?Closure
    {
        if (! app()->bound('backup_v2.proven_restore.restorer')) {
            $this->warn('No sandbox restorer bound (backup_v2.proven_restore.restorer) — reporting eligible sites only.');

            return null;
        }

        $restorer = app('backup_v2.proven_restore.restorer');

        return $restorer instanceof Closure ? $restorer : null;
    }
}
