<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\Jobs\CreateBackup;
use App\Livewire\Traits\WithSorting;
use App\Livewire\Traits\WithTableFilters;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BackupsOverview extends Component
{
    use WithSorting, WithTableFilters;

    protected string $defaultSortBy = 'site';

    protected string $defaultSortDir = 'asc';

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Backup::count(),
            'completed' => Backup::where('status', 'completed')->count(),
            'failed' => Backup::where('status', 'failed')->count(),
            'in_progress' => Backup::whereIn('status', ['pending', 'in_progress'])->count(),
        ];
    }

    public function backupAllSites(): void
    {
        $rateLimitKey = 'bulk-backup-all:'.auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 3, fn () => true, 3600)) {
            $this->dispatch('notify', type: 'error', message: 'Too many bulk backup requests. Please wait before trying again.');

            return;
        }

        $configs = BackupConfig::whereHas('site')
            ->where('is_enabled', true)
            ->with(['site', 'storageDestination'])
            ->get();

        $queued = 0;
        $defaultDestination = null;
        foreach ($configs as $config) {
            /** @var Site|null $site */
            $site = $config->site;
            if (! $site || ! $site->is_connected) {
                continue;
            }

            $destination = $config->storageDestination;
            if (! $destination) {
                $destination = $defaultDestination ??= StorageDestination::where('is_default', true)
                    ->where('is_active', true)
                    ->first();
            }

            if (! $destination) {
                continue;
            }

            $backup = Backup::create([
                'site_id' => $site->id,
                'storage_destination_id' => $destination->id,
                'type' => $config->type ?? 'full',
                'trigger' => 'manual_bulk',
                'status' => 'pending',
                'stage' => 'queued',
                'progress_percent' => 0,
                'progress_message' => 'Backup queued, waiting to start...',
                'includes_database' => true,
                'includes_files' => ($config->type ?? 'full') === 'full',
                'wp_version' => $site->wp_version,
                'php_version' => $site->php_version,
                'started_at' => now(),
            ]);

            CreateBackup::dispatch($site, $config->type ?? 'full', 'manual_bulk', $destination->id, $backup->id);
            $queued++;
        }

        $this->dispatch('notify', type: 'success', message: "Queued backups for {$queued} site(s).");
    }

    public function deleteBackup(int $backupId): void
    {
        $backup = Backup::findOrFail($backupId);

        if ($backup->is_locked) {
            session()->flash('backup-error', 'Cannot delete a locked backup.');

            return;
        }

        try {
            if ($backup->storageDestination && $backup->file_path) {
                $driver = StorageFactory::make($backup->storageDestination);
                $driver->delete($backup->file_path);
                $backup->storageDestination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
            }
        } catch (\Exception) {
            // Continue with deletion even if storage removal fails
        }

        $backup->delete();
        session()->flash('backup-success', 'Backup deleted.');
    }

    public function bulkDelete(array $ids): void
    {
        $backups = Backup::whereIn('id', $ids)->where('is_locked', false)->get();
        $count = 0;

        foreach ($backups as $backup) {
            try {
                if ($backup->storageDestination && $backup->file_path) {
                    $driver = StorageFactory::make($backup->storageDestination);
                    $driver->delete($backup->file_path);
                    $backup->storageDestination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
                }
            } catch (\Exception) {
                // Continue
            }
            $backup->delete();
            $count++;
        }

        $skipped = count($ids) - $count;
        $msg = "{$count} backup(s) deleted.";
        if ($skipped > 0) {
            $msg .= " {$skipped} locked backup(s) skipped.";
        }
        session()->flash('backup-success', $msg);
    }

    public function render()
    {
        $backups = Backup::query()
            ->with(['site', 'storageDestination'])
            ->when($this->search, function ($q) {
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('url', 'ilike', "%{$this->search}%"));
            })
            ->when($this->filter !== 'all', function ($q) {
                if ($this->filter === 'in_progress') {
                    return $q->whereIn('backups.status', ['pending', 'in_progress']);
                }

                return $q->where('backups.status', $this->filter);
            })
            ->join('sites', 'backups.site_id', '=', 'sites.id')
            ->orderBy(
                match (true) {
                    $this->sortBy === 'site' => 'sites.sort_order',
                    in_array($this->sortBy, ['created_at', 'type', 'file_size', 'status']) => "backups.{$this->sortBy}",
                    default => 'sites.sort_order',
                },
                $this->sortDir
            )
            ->select('backups.*')
            ->paginate(25);

        return view('livewire.backups.backups-overview', [
            'backups' => $backups,
        ])->layout('components.layouts.app', ['title' => 'Backups']);
    }
}
