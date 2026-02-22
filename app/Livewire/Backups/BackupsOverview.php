<?php

namespace App\Livewire\Backups;

use App\Jobs\CreateBackup;
use App\Livewire\Traits\WithTableFilters;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BackupsOverview extends Component
{
    use WithTableFilters;

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
        $rateLimitKey = 'bulk-backup-all:' . auth()->id();
        if (!RateLimiter::attempt($rateLimitKey, 3, fn () => true, 3600)) {
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
            $site = $config->site;
            if (!$site || !$site->is_connected) {
                continue;
            }

            $destination = $config->storageDestination;
            if (!$destination) {
                $destination = $defaultDestination ??= StorageDestination::where('is_default', true)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$destination) {
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

    public function render()
    {
        $backups = Backup::query()
            ->with(['site', 'storageDestination'])
            ->when($this->search, function ($q) {
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'like', "%{$this->search}%")
                    ->orWhere('domain', 'like', "%{$this->search}%"));
            })
            ->when($this->filter !== 'all', function ($q) {
                if ($this->filter === 'in_progress') {
                    return $q->whereIn('status', ['pending', 'in_progress']);
                }
                return $q->where('status', $this->filter);
            })
            ->join('sites', 'backups.site_id', '=', 'sites.id')
            ->orderBy('sites.sort_order', 'asc')
            ->orderByDesc('backups.created_at')
            ->select('backups.*')
            ->paginate(25);

        return view('livewire.backups.backups-overview', [
            'backups' => $backups,
        ])->layout('components.layouts.app', ['title' => 'Backups']);
    }
}
