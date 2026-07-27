<?php

declare(strict_types=1);

namespace App\Livewire\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreMode;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Backup\V2\Support\BackupV2Access;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * V2 backup console — BACKUP DETAIL (P5). Flag-gated, admin-only. Renders the full
 * anatomy of one session: state, stage, progress, heartbeat, attempts, duration,
 * resource profile, full/incremental + full base + chain, manifest / objects /
 * checksums, storage, errors, verification, and restore options.
 *
 * Reads only from the session row (DB truth: checkpoint + confirmed_objects) — no
 * live host / S3 call, so it is safe with the engine inert.
 */
class BackupV2Detail extends Component
{
    use WithSiteAuthorization;

    public BackupSession $session;

    public bool $enabled = false;

    public function mount(BackupSession $session): void
    {
        $this->session = $session;
        $this->enabled = BackupV2Access::allows(auth()->user());
        if ($this->enabled && $session->site instanceof Site) {
            $this->authorizeSiteAccess($session->site);
        }
    }

    /**
     * Ordered-happy-path progress, 0–100.
     */
    #[Computed]
    public function progressPercent(): int
    {
        $order = [
            BackupSessionState::Requested,
            BackupSessionState::CapabilityCheck,
            BackupSessionState::Inventory,
            BackupSessionState::DatabaseExport,
            BackupSessionState::FileDiff,
            BackupSessionState::Chunking,
            BackupSessionState::UploadInitializing,
            BackupSessionState::Uploading,
            BackupSessionState::UploadVerifying,
            BackupSessionState::Finalizing,
            BackupSessionState::Completed,
        ];

        if ($this->session->state === BackupSessionState::Completed) {
            return 100;
        }

        $idx = array_search($this->session->state, $order, true);
        if ($idx === false) {
            // Side state (retry_wait / paused / failed / corrupt / cancelled).
            return 0;
        }

        return (int) round($idx / (count($order) - 1) * 100);
    }

    /**
     * Confirmed objects (key, size, sha256, kind).
     *
     * @return list<array<string,mixed>>
     */
    #[Computed]
    public function objects(): array
    {
        return array_values($this->session->confirmed_objects ?? []);
    }

    /**
     * Human duration (started → completed / now), or null.
     */
    #[Computed]
    public function duration(): ?string
    {
        if ($this->session->started_at === null) {
            return null;
        }

        $end = $this->session->completed_at ?? now();

        return $this->session->started_at->diffForHumans($end, ['syntax' => true, 'parts' => 2]);
    }

    /**
     * The ordered chain this session belongs to (DB rows only).
     *
     * @return \Illuminate\Support\Collection<int, BackupSession>
     */
    #[Computed]
    public function chain()
    {
        if ($this->session->full_base_id === null) {
            // A full: itself + its completed increments.
            return BackupSession::query()
                ->where(function ($q) {
                    $q->where('id', $this->session->id)
                        ->orWhere('full_base_id', $this->session->id);
                })
                ->orderByRaw('COALESCE(chain_position, 0)')
                ->get();
        }

        return BackupSession::query()
            ->where('id', $this->session->full_base_id)
            ->orWhere('full_base_id', $this->session->full_base_id)
            ->orderByRaw('COALESCE(chain_position, 0)')
            ->get();
    }

    // ── restore options ──────────────────────────────────────────────────

    public function restore(string $mode = 'safe_merge'): void
    {
        if (! $this->enabled) {
            abort(404);
        }
        if ($this->session->site instanceof Site) {
            $this->authorizeSiteModification($this->session->site);
        }
        if ($this->session->state !== BackupSessionState::Completed) {
            session()->flash('backup-v2-error', 'Only a completed backup can be restored.');

            return;
        }

        $restore = app(SessionActions::class)->startRestore($this->session, RestoreMode::from($mode));
        session()->flash('backup-v2-success', "Restore session #{$restore->id} queued ({$mode}).");
    }

    public function toggleProtected(): void
    {
        if (! $this->enabled) {
            abort(404);
        }
        if ($this->session->site instanceof Site) {
            $this->authorizeSiteModification($this->session->site);
        }
        app(SessionActions::class)->setProtected($this->session, ! $this->session->protected);
        $this->session->refresh();
        session()->flash('backup-v2-success', $this->session->protected ? 'Backup protected.' : 'Backup unprotected.');
    }

    public function render()
    {
        return view('livewire.backup.v2.backup-v2-detail')
            ->layout('components.layouts.app', ['title' => 'Backup V2 — session #'.$this->session->id]);
    }
}
