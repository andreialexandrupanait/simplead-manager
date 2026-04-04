<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

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
