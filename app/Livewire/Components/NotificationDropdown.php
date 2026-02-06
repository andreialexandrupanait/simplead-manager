<?php

namespace App\Livewire\Components;

use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotificationDropdown extends Component
{
    public bool $sidebarMode = false;
    public array $dismissedAlerts = [];

    #[Computed]
    public function alerts(): array
    {
        $all = Cache::remember('global_alerts', 120, fn () => app(DashboardService::class)->getAlerts());

        return array_values(array_filter($all, fn ($a) => !in_array($a['key'], $this->dismissedAlerts)));
    }

    #[Computed]
    public function count(): int
    {
        return count($this->alerts);
    }

    public function dismissAlert(string $key): void
    {
        if (!in_array($key, $this->dismissedAlerts)) {
            $this->dismissedAlerts[] = $key;
        }
        unset($this->alerts);
        unset($this->count);
    }

    public function dismissAll(): void
    {
        $this->dismissedAlerts = collect($this->alerts)->pluck('key')->merge($this->dismissedAlerts)->unique()->values()->toArray();
        unset($this->alerts);
        unset($this->count);
    }

    public function retryFailedBackups(): void
    {
        $failedSiteIds = Backup::whereHas('site')
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->pluck('site_id')
            ->unique();

        $sites = Site::whereIn('id', $failedSiteIds)->get();

        foreach ($sites as $site) {
            CreateBackup::dispatch($site, 'full', 'manual');
        }

        Cache::forget('global_alerts');
        unset($this->alerts);
        unset($this->count);

        session()->flash('message', "Retry dispatched for {$sites->count()} site(s).");
    }

    public function render()
    {
        return view('livewire.components.notification-dropdown');
    }
}
