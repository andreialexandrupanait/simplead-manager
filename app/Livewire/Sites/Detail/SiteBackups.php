<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Livewire\Traits\WithBackupActions;
use App\Livewire\Traits\WithBackupProgress;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithSorting;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class SiteBackups extends Component
{
    use WithBackupActions, WithBackupProgress, WithPagination, WithSiteAuthorization, WithSorting;

    protected string $defaultSortBy = 'created_at';

    protected string $defaultSortDir = 'desc';

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        // If there's an in-progress backup on page load, track it
        /** @var Backup|null $active */
        $active = $this->site->backups()
            ->whereIn('status', ['pending', 'in_progress'])
            ->latest()
            ->first();
        if ($active) {
            $this->trackingBackupId = $active->id;
        }

        // If there's an in-progress restore on page load, track it
        /** @var Backup|null $activeRestore */
        $activeRestore = $this->site->backups()
            ->whereIn('restore_status', ['pending', 'in_progress'])
            ->latest()
            ->first();
        if ($activeRestore) {
            $this->trackingRestoreBackupId = $activeRestore->id;
        }
    }

    #[Computed]
    public function backupConfig()
    {
        return $this->site->backupConfig;
    }

    #[Computed]
    public function storageDestinations()
    {
        return StorageDestination::where('is_active', true)->get();
    }

    /**
     * C-08: the site's most recent proven-restore result (a real restore into the
     * sandbox WP), or null if the site isn't enrolled / hasn't been proven yet.
     */
    #[Computed]
    public function provenRestore(): ?\App\Models\ProvenRestore
    {
        if (! $this->site->proven_restore_enabled) {
            return null;
        }

        return $this->site->latestProvenRestore()->first();
    }

    /**
     * Whether this site can actually be backed up, and what is missing if not.
     *
     * Installing the engine on a site lived on a separate console behind a
     * feature flag and an admin check — a screen nobody could find, for the one
     * step a newly connected site needs before it is protected at all. A site
     * would sit here looking like a backup screen and quietly do nothing.
     *
     * Only consulted for the banner: a site that is fine shows nothing.
     */
    #[Computed]
    public function engineStatus(): ?array
    {
        $status = app(\App\Services\Backup\FleetMigrationService::class)->status($this->site);

        if ($status['plugin_ok'] && $status['answering'] && ! $status['engine_silent']) {
            return null;
        }

        return $status;
    }

    /**
     * Put the engine on this site.
     *
     * Pushes the connector if it is too old, installs the backup plugin, probes
     * it, and records the result — all of which the job already did for the fleet
     * console. What changes is that a person looking at an unprotected site can
     * reach it.
     */
    public function installEngine(): void
    {
        $this->authorizeSiteModification($this->site);

        \App\Jobs\MigrateSiteToBackupV2::dispatch($this->site, (string) \Illuminate\Support\Str::uuid());

        unset($this->engineStatus);
        session()->flash('backup-success', __('Installing the backup engine on this site — this takes a minute.'));
    }

    #[Computed]
    public function storageUsage()
    {
        $totalSize = (int) Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->sum('file_size');

        return $this->formatBytes($totalSize);
    }

    /**
     * How many restore points this site actually has.
     *
     * A computed property rather than a query in the view: the storage card used
     * to run `$site->backups()->where(...)->count()` inline, which is a database
     * round trip on every Livewire poll — and this page polls every three seconds
     * while a backup is running.
     */
    #[Computed]
    public function completedBackupCount(): int
    {
        return Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->count();
    }

    /**
     * How big the next backup will be.
     *
     * This guessed: database size plus uploads size, times a 0.6 compression
     * factor. Both of those come from the connector's inventory and are null on
     * plenty of sites, so a site with two dozen real backups averaging 450 MB was
     * told "A full backup is about < 1 MB" — an estimate contradicted by the
     * table directly beneath it.
     *
     * The last completed backup is not an estimate at all. It is the measurement,
     * of this site, on this storage, through this engine. The guess survives only
     * for a site that has never backed up, which is the one case where there is
     * nothing better.
     */
    #[Computed]
    public function estimatedBackupSize(): string
    {
        $lastBytes = (int) Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->value('file_size');

        if ($lastBytes > 0) {
            return $this->formatBytes($lastBytes);
        }

        $totalMb = ((float) ($this->site->db_size_mb ?? 0) + (float) ($this->site->uploads_size_mb ?? 0)) * 0.6;

        return $totalMb < 1 ? '< 1 MB' : round($totalMb, 1).' MB';
    }

    #[Computed]
    public function storageQuotaInfo(): ?array
    {
        $config = $this->site->backupConfig;
        if (! $config?->storage_destination_id) {
            return null;
        }

        $destination = StorageDestination::find($config->storage_destination_id);
        if (! $destination || ! $destination->quota_bytes) {
            return null;
        }

        $percent = $destination->usage_percent;
        if ($percent === null) {
            return null;
        }

        $level = 'ok';
        if ($percent >= 90) {
            $level = 'error';
        } elseif ($percent >= 75) {
            $level = 'warning';
        }

        return [
            'percent' => $percent,
            'used' => $destination->used_formatted,
            'total' => $this->formatBytes($destination->quota_bytes),
            'level' => $level,
        ];
    }

    /**
     * Can an incremental actually be built right now?
     *
     * Two wrong answers before this one, and they failed in opposite directions.
     *
     * It first asked whether any completed backup had a `manifest_path` — a
     * column belonging to the retired engine, which kept its manifest in the
     * database. This one writes the manifest into storage and leaves the column
     * null, so the answer was permanently no and the button never appeared.
     *
     * Then it asked ChainPlanner, which answers a different question: what a
     * SCHEDULED run would produce. That is governed by the site's
     * incremental_frequency, so a site with a perfectly good full backup and no
     * incremental schedule still got no button — the person is not asking what
     * the schedule would do, they are asking to make one now.
     *
     * The question this button is actually asking is whether there is a completed
     * full backup to build on. That is all a manual incremental needs.
     */
    #[Computed]
    public function hasFullBackupWithManifest(): bool
    {
        return BackupSession::query()
            ->where('site_id', $this->site->id)
            ->where('type', 'full')
            ->where('state', BackupSessionState::Completed->value)
            ->exists();
    }

    public function getBackupHistoryProperty()
    {
        return $this->site->backups()
            ->with(['storageDestination', 'parentBackup'])
            ->selectSub(
                Backup::selectRaw('file_size')
                    ->whereColumn('site_id', 'backups.site_id')
                    ->where('status', 'completed')
                    ->whereColumn('id', '<', 'backups.id')
                    ->whereNotNull('file_size')
                    ->orderByDesc('id')
                    ->limit(1),
                '_previous_file_size'
            )
            ->select('backups.*')
            ->orderBy(
                in_array($this->sortBy, ['created_at', 'type', 'file_size', 'status']) ? $this->sortBy : 'created_at',
                $this->sortDir
            )
            ->paginate(15);
    }

    #[On('schedule-saved')]
    public function refreshData(): void
    {
        unset($this->backupConfig);
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-backups', [
            'backupHistory' => $this->backupHistory,
        ])
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Backups',
            ]);
    }
}
