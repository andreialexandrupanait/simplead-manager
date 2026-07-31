<?php

declare(strict_types=1);

namespace App\Livewire\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreMode;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Backup\V2\Quota\QuotaExceededException;
use App\Backup\V2\Quota\QuotaService;
use App\Backup\V2\Support\BackupV2Access;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\StorageDestination;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * V2 backup console — PER-SITE (P5). Flag-gated, admin-only. Exposes the full
 * per-site action surface (backup now / full / incremental / db-only / files-only,
 * schedule, resource profile, scope, exclusions + preview/estimate, history,
 * chains, progress, verify, download, restore, retry, resume, pause, cancel,
 * protect, delete, storage destination) — every action routed through the testable
 * SessionActions seam, itself inert in production without the engine flags.
 */
class SiteBackupV2 extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public bool $enabled = false;

    // ── new-backup form ──────────────────────────────────────────────────
    public string $backupType = 'full';

    public string $resourceProfile = 'low_impact';

    public bool $scopeDatabase = true;

    public bool $scopeFiles = true;

    public string $exclusionsText = '';

    /** @var array<string,mixed>|null */
    public ?array $previewResult = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->enabled = BackupV2Access::allows(auth()->user());
        if ($this->enabled) {
            $this->authorizeSiteAccess($site);
        }
        $this->resourceProfile = (string) config('backup_v2.default_profile', 'low_impact');
    }

    /**
     * @return \Illuminate\Support\Collection<int, BackupSession>
     */
    #[Computed]
    public function history()
    {
        if (! $this->enabled) {
            return collect();
        }

        return BackupSession::query()
            ->where('site_id', $this->site->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Completed fulls with their descending incrementals (chains).
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    #[Computed]
    public function chains()
    {
        if (! $this->enabled) {
            return collect();
        }

        $completed = BackupSession::query()
            ->where('site_id', $this->site->id)
            ->where('state', BackupSessionState::Completed->value)
            ->whereIn('type', ['full', 'incremental'])
            ->orderBy('id')
            ->get();

        $incsByBase = $completed->where('type', 'incremental')->groupBy('full_base_id');

        return $completed->where('type', 'full')->map(fn (BackupSession $full): array => [
            'full' => $full,
            'increments' => ($incsByBase[$full->id] ?? collect())->sortBy('chain_position')->values(),
        ])->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, RestoreSession>
     */
    #[Computed]
    public function restores()
    {
        if (! $this->enabled) {
            return collect();
        }

        return RestoreSession::query()
            ->where('site_id', $this->site->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * @return array<string,mixed>|null
     */
    #[Computed]
    public function destination(): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $d = StorageDestination::resolveForSite($this->site);
        if ($d === null) {
            return null;
        }

        $quota = app(QuotaService::class);

        return [
            'model' => $d,
            'used_bytes' => $quota->usedBytes($d),
            'quota_bytes' => $quota->quotaBytes($d),
            'percent' => $quota->usagePercent($d),
            'over_warn' => $quota->isOverWarnThreshold($d),
        ];
    }

    // ── actions ──────────────────────────────────────────────────────────

    public function startBackup(string $type): void
    {
        if (! $this->guardModify()) {
            return;
        }

        $exclusions = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->exclusionsText) ?: [])));

        try {
            $session = app(SessionActions::class)->startBackup($this->site, $type, [
                'scope' => [
                    'database' => in_array($type, ['full', 'database'], true) ? $this->scopeDatabase : false,
                    'files' => in_array($type, ['full', 'files'], true) ? $this->scopeFiles : false,
                ],
                'exclusions' => $exclusions,
                'resource_profile' => $this->resourceProfile,
            ]);
        } catch (QuotaExceededException $e) {
            session()->flash('backup-v2-error', $e->getMessage());

            return;
        }

        session()->flash('backup-v2-success', "Backup session #{$session->id} queued ({$type}).");
    }

    /**
     * Estimate what a backup would move — a cheap preview from the last inventory
     * on record (no live host call, safe with the engine inert).
     */
    public function preview(): void
    {
        if (! $this->enabled) {
            return;
        }

        $last = BackupSession::query()
            ->where('site_id', $this->site->id)
            ->whereNotNull('checkpoint')
            ->orderByDesc('id')
            ->first();

        $inv = $last?->checkpoint['files_inventory'] ?? null;

        $this->previewResult = [
            'based_on_session' => $last?->id,
            'total_files' => (int) ($inv['total_files'] ?? 0),
            'total_bytes' => (int) ($inv['total_bytes'] ?? 0),
            'excluded_files' => (int) ($inv['excluded_files'] ?? 0),
            'excluded_bytes' => (int) ($inv['excluded_bytes'] ?? 0),
            'exclusions' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->exclusionsText) ?: []))),
        ];
    }

    public function pauseSession(int $id): void
    {
        $this->withSession($id, fn (BackupSession $s) => app(SessionActions::class)->pause($s), 'Backup paused.');
    }

    public function cancelSession(int $id): void
    {
        $this->withSession($id, fn (BackupSession $s) => app(SessionActions::class)->cancel($s), 'Cancellation requested.');
    }

    public function retrySession(int $id): void
    {
        $this->withSession($id, fn (BackupSession $s) => app(SessionActions::class)->retry($s), 'Backup retry queued.');
    }

    public function resumeSession(int $id): void
    {
        $this->withSession($id, fn (BackupSession $s) => app(SessionActions::class)->resume($s), 'Backup resume queued.');
    }

    public function verifySession(int $id): void
    {
        $this->withSession($id, fn (BackupSession $s) => app(SessionActions::class)->requestVerification($s), 'Verification queued.');
    }

    public function protectSession(int $id, bool $protected): void
    {
        $this->withSession($id, fn (BackupSession $s) => app(SessionActions::class)->setProtected($s, $protected), $protected ? 'Backup protected.' : 'Backup unprotected.');
    }

    public function deleteSession(int $id): void
    {
        $this->withSession($id, function (BackupSession $s) {
            app(SessionActions::class)->delete($s);
        }, 'Backup deleted.');
    }

    public function restoreSession(int $id, string $mode = 'safe_merge'): void
    {
        $this->withSession($id, function (BackupSession $s) use ($mode) {
            $restore = app(SessionActions::class)->startRestore($s, RestoreMode::from($mode));
            session()->flash('backup-v2-success', "Restore session #{$restore->id} queued.");
        }, null);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function guardModify(): bool
    {
        if (! $this->enabled) {
            abort(404);
        }
        $this->authorizeSiteModification($this->site);

        return true;
    }

    private function withSession(int $id, callable $work, ?string $success): void
    {
        if (! $this->guardModify()) {
            return;
        }

        $session = BackupSession::where('site_id', $this->site->id)->find($id);
        if ($session === null) {
            session()->flash('backup-v2-error', 'Backup session not found.');

            return;
        }

        try {
            $work($session);
        } catch (\RuntimeException $e) {
            session()->flash('backup-v2-error', $e->getMessage());

            return;
        }

        if ($success !== null) {
            session()->flash('backup-v2-success', $success);
        }
    }

    public function render()
    {
        return view('livewire.backup.v2.site-backup-v2')
            ->layout('components.layouts.app', ['title' => 'Backup V2 — '.$this->site->name]);
    }
}
