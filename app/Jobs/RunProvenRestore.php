<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\ProvenRestore;
use App\Models\Site;
use App\Services\Backup\SandboxRestoreService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * C-08: weekly proven restore. Picks the enabled site whose backup has gone
 * longest without a proof (rotation), restores its most recent backup into the
 * isolated sandbox, health-checks it, records the outcome, and alerts on failure.
 * Enabled only for the pilot/test sites (`sites.proven_restore_enabled`).
 */
class RunProvenRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('backups');
    }

    public function handle(SandboxRestoreService $service): void
    {
        $sandbox = Site::where('is_sandbox', true)->first();
        if (! $sandbox) {
            Log::info('Proven restore skipped: no sandbox site is provisioned.');

            return;
        }

        $site = $this->nextSiteDue();
        if (! $site) {
            Log::info('Proven restore skipped: no proven_restore_enabled site.');

            return;
        }

        /** @var \App\Models\Backup|null $backup */
        $backup = $site->backups()
            ->where('status', BackupStatus::Completed)
            ->latest()
            ->first();

        if (! $backup) {
            $this->record($site, null, false, [], 'no completed backup to prove');
            $this->alertFailure($site, 'has no completed backup to prove');

            return;
        }

        $result = $service->prove($sandbox, $backup);

        $this->record($site, $backup->id, $result['passed'], $result['checks'], $result['error']);

        if (! $result['passed']) {
            $failed = collect($result['checks'])->filter(fn ($v) => $v === false)->keys()->implode(', ');
            $reason = $result['error'] ?: ('failed checks: '.($failed ?: 'unknown'));
            $this->alertFailure($site, $reason);
        }
    }

    private function nextSiteDue(): ?Site
    {
        return Site::query()
            ->where('proven_restore_enabled', true)
            ->where('is_sandbox', false)
            ->with('latestProvenRestore')
            ->get()
            ->sortBy(fn (Site $s) => $s->latestProvenRestore?->ran_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function record(Site $site, ?int $backupId, bool $passed, array $checks, ?string $error): void
    {
        ProvenRestore::create([
            'site_id' => $site->id,
            'backup_id' => $backupId,
            'status' => $passed ? ProvenRestore::STATUS_PASSED : ProvenRestore::STATUS_FAILED,
            'checks' => $checks,
            'error' => $error,
            'ran_at' => now(),
        ]);
    }

    private function alertFailure(Site $site, string $reason): void
    {
        NotificationService::notifyAppEventSlim(
            'proven_restore_failed',
            "\xE2\x9A\xA0\xEF\xB8\x8F Proven restore FAILED for *{$site->name}* — {$reason}",
            deepLink: null,
            severity: 'critical',
        );
    }
}
