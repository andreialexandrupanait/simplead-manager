<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Enums\BackupStatus;
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

    #[Computed]
    public function storageUsage()
    {
        $totalSize = (int) Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->sum('file_size');

        return $this->formatBytes($totalSize);
    }

    #[Computed]
    public function estimatedBackupSize(): string
    {
        $dbMb = (float) ($this->site->db_size_mb ?? 0);
        $uploadsMb = (float) ($this->site->uploads_size_mb ?? 0);
        $totalMb = ($dbMb + $uploadsMb) * 0.6; // ~60% compression factor

        if ($totalMb < 1) {
            return '< 1 MB';
        }

        return round($totalMb, 1).' MB';
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
     * C-12: offsite backup health for the banner. Returns null when the site's
     * backups are safely reaching a healthy offsite destination; otherwise a
     * ['level','message',...] describing the problem:
     *   - 'missing'  — no active, non-local offsite destination (and data exists)
     *   - 'failing'  — the destination failed its last credential check (recorded
     *                  by the daily ValidateConnection job)
     *   - 'stale'    — a backup old enough to have replicated has no offsite replica
     *
     * @return array{level:string,message:string,error?:?string,tested_at?:mixed,last_replicated_at?:mixed}|null
     */
    #[Computed]
    public function offsiteStatus(): ?array
    {
        $config = $this->site->backupConfig;
        $hasBackups = $this->site->backups()->where('status', BackupStatus::Completed)->exists();

        $secondaryId = $config?->secondary_storage_destination_id;
        $destination = $secondaryId ? StorageDestination::find($secondaryId) : null;

        // No usable offsite replica target — only warn once there's data worth
        // protecting offsite (don't nag a brand-new site with no backups yet).
        if (! $destination || ! $destination->is_active || $destination->type === 'local') {
            return $hasBackups
                ? ['level' => 'missing', 'message' => __('No active offsite backup destination — backups exist in only one place.')]
                : null;
        }

        // Credentials known bad (recorded by the daily ValidateConnection job).
        if ($destination->last_test_passed === false) {
            return [
                'level' => 'failing',
                'message' => __('Offsite destination ":name" failed its last credential check.', ['name' => $destination->name]),
                'error' => $destination->last_test_error,
                'tested_at' => $destination->last_tested_at,
            ];
        }

        // Replication recency: the newest backup old enough to have replicated
        // (grace of 6h for the async replica job) should carry a successful
        // replica to this destination.
        /** @var Backup|null $newest */
        $newest = $this->site->backups()->where('status', BackupStatus::Completed)
            ->where('created_at', '<', now()->subHours(6))
            ->latest()
            ->first();

        if ($newest && ! $this->backupHasOffsiteReplica($newest, $destination->id)) {
            return [
                'level' => 'stale',
                'message' => __('Backups are not reaching the offsite destination ":name".', ['name' => $destination->name]),
                'last_replicated_at' => $this->offsiteLastReplicatedAt($destination->id),
            ];
        }

        return null;
    }

    private function backupHasOffsiteReplica(Backup $backup, int $destinationId): bool
    {
        foreach ($backup->replicas ?? [] as $replica) {
            if (($replica['destination_id'] ?? null) === $destinationId
                && ($replica['status'] ?? null) === 'completed') {
                return true;
            }
        }

        return false;
    }

    private function offsiteLastReplicatedAt(int $destinationId): ?\Illuminate\Support\Carbon
    {
        /** @var \Illuminate\Support\Carbon|null $latest */
        $latest = null;

        $backups = $this->site->backups()->where('status', BackupStatus::Completed)
            ->whereJsonLength('replicas', '>', 0)
            ->latest()
            ->limit(20)
            ->get(['id', 'replicas']);

        foreach ($backups as $backup) {
            foreach ($backup->replicas ?? [] as $replica) {
                if (($replica['destination_id'] ?? null) !== $destinationId
                    || ($replica['status'] ?? null) !== 'completed'
                    || empty($replica['uploaded_at'])) {
                    continue;
                }
                $at = \Illuminate\Support\Carbon::parse($replica['uploaded_at']);
                if ($latest === null || $at->greaterThan($latest)) {
                    $latest = $at;
                }
            }
        }

        return $latest;
    }

    #[Computed]
    public function hasFullBackupWithManifest(): bool
    {
        return Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereNotNull('manifest_path')
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
