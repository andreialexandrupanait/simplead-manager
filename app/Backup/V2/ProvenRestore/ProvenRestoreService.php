<?php

declare(strict_types=1);

namespace App\Backup\V2\ProvenRestore;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Support\BackupLogger;
use App\Models\ProvenRestore;
use App\Models\Site;
use Closure;
use Throwable;

/**
 * PROVEN RESTORE for the V2 engine — the check that a backup is not merely
 * present and internally consistent, but actually RESTORABLE.
 *
 * It takes a site's latest valid V2 backup, restores it into an ISOLATED sandbox
 * (a second WordPress / a second filesystem+DB, never the live site), runs a
 * health-check against the restored sandbox (files present with matching content,
 * expected tables/rows, the site responds), and writes a `proven_restores` row
 * recording the outcome. This is what closes the "proven_restores empty (0)"
 * defect: every run appends a real passed/failed row with the per-check evidence.
 *
 * Transport is INJECTED (a `$restorer` closure that drives a RestoreRunner against
 * sandbox clients + a health probe), so the command wires the real HTTP restore
 * client while tests wire the in-process plugin restore engine. The service owns
 * backup selection, the RestoreSession lifecycle and the ProvenRestore record.
 */
final class ProvenRestoreService
{
    private readonly BackupLogger $logger;

    public function __construct(?BackupLogger $logger = null)
    {
        $this->logger = ($logger ?? new BackupLogger)->forSession('proven-restore', null, null);
    }

    /**
     * The latest completed + create-verified V2 backup for a site, or null when the
     * site has no restorable, verified backup yet.
     */
    public function latestValidBackup(int $siteId): ?BackupSession
    {
        return BackupSession::query()
            ->where('site_id', $siteId)
            ->where('state', BackupSessionState::Completed->value)
            ->whereNotNull('verified_at')
            ->whereIn('type', ['full', 'incremental'])
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('backup_verifications')
                    ->whereColumn('backup_verifications.backup_session_id', 'backup_sessions.id')
                    ->where('backup_verifications.kind', BackupVerification::KIND_CREATE)
                    ->where('backup_verifications.status', BackupVerification::STATUS_PASSED);
            })
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Prove that a site's backup restores. Resolves the latest valid backup when
     * $backup is null.
     *
     * @param  Closure(RestoreSession, BackupSession): array{ok:bool, checks:array<string,mixed>, error?:string|null}  $restorer
     *                                                                                                                            Drives the RestoreSession to a terminal state against the sandbox and returns
     *                                                                                                                            the health outcome. The caller wires the RestoreRunner + sandbox clients.
     * @param  array<string,mixed>  $scope
     */
    public function run(
        Site $site,
        ?BackupSession $backup,
        Closure $restorer,
        string $mode = 'mirror',
        array $scope = ['database' => false, 'files' => true],
    ): ProvenRestore {
        $backup ??= $this->latestValidBackup($site->id);

        if (! $backup instanceof BackupSession) {
            return $this->record(
                $site,
                null,
                null,
                ProvenRestore::STATUS_FAILED,
                ['reason' => 'no_valid_backup'],
                'No valid, create-verified V2 backup is available to prove-restore.',
            );
        }

        $restore = RestoreSession::create([
            'site_id' => $site->id,
            'backup_session_id' => $backup->id,
            'mode' => $mode,
            'scope' => $scope,
            'state' => RestoreSessionState::Requested,
            'checkpoint' => [],
            'idempotency_key' => 'proven-'.$backup->id.'-'.uniqid('', true),
        ]);

        try {
            $outcome = $restorer($restore, $backup);
        } catch (Throwable $e) {
            $restore->refresh();

            return $this->record(
                $site,
                $backup,
                $restore,
                ProvenRestore::STATUS_FAILED,
                [
                    'backup_session_id' => $backup->id,
                    'restore_session_id' => $restore->id,
                    'restore_state' => $restore->state->value,
                    'exception' => $e->getMessage(),
                ],
                $e->getMessage(),
            );
        }

        $restore->refresh();

        $reachedCompleted = $restore->state === RestoreSessionState::Completed;
        $healthOk = (bool) $outcome['ok'];
        $status = ($reachedCompleted && $healthOk)
            ? ProvenRestore::STATUS_PASSED
            : ProvenRestore::STATUS_FAILED;

        $checks = array_merge([
            'backup_session_id' => $backup->id,
            'restore_session_id' => $restore->id,
            'restore_state' => $restore->state->value,
            'health_ok' => $healthOk,
        ], $outcome['checks']);

        $error = $outcome['error'] ?? ($status === ProvenRestore::STATUS_FAILED ? $restore->error_message : null);

        return $this->record($site, $backup, $restore, $status, $checks, $error);
    }

    /**
     * @param  array<string,mixed>  $checks
     */
    private function record(
        Site $site,
        ?BackupSession $backup,
        ?RestoreSession $restore,
        string $status,
        array $checks,
        ?string $error,
    ): ProvenRestore {
        $proven = new ProvenRestore([
            // ProvenRestore.backup_id is the V1 backups link; a V2 session may not
            // materialise a V1 row, so this is nullable. The V2 backup_session_id is
            // always captured in checks for full traceability.
            'site_id' => $site->id,
            'backup_id' => $backup?->backup_id,
            'status' => $status,
            'checks' => $checks,
            'error' => $error,
            'ran_at' => now(),
        ]);
        $proven->save();

        $this->logger->log(
            $status === ProvenRestore::STATUS_PASSED ? 'info' : 'warning',
            'proven restore recorded',
            [
                'site_id' => $site->id,
                'backup_session_id' => $backup?->id,
                'restore_session_id' => $restore?->id,
                'status' => $status,
                'error' => $error,
            ],
        );

        return $proven;
    }
}
