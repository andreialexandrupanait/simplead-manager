<?php

namespace App\Livewire\Components;

use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotificationDropdown extends Component
{
    public bool $sidebarMode = false;

    public array $dismissedAlerts = [];

    public function mount(): void
    {
        $this->dismissedAlerts = $this->getPersistedDismissals();
    }

    #[Computed]
    public function alerts(): array
    {
        $all = Cache::remember('global_alerts', 120, fn () => app(DashboardService::class)->getAlerts());

        return array_values(array_filter($all, fn ($a) => ! in_array($a['key'], $this->dismissedAlerts)));
    }

    #[Computed]
    public function count(): int
    {
        return count($this->alerts);
    }

    public function dismissAlert(string $key): void
    {
        if (! in_array($key, $this->dismissedAlerts)) {
            $this->dismissedAlerts[] = $key;
        }
        $this->persistDismissals();
        unset($this->alerts);
        unset($this->count);
    }

    public function dismissAll(): void
    {
        Cache::forget('global_alerts');
        $freshAlerts = app(DashboardService::class)->getAlerts();
        $allKeys = collect($freshAlerts)->pluck('key')->toArray();
        $this->dismissedAlerts = array_values(array_unique(array_merge($this->dismissedAlerts, $allKeys)));
        $this->persistDismissals();
        unset($this->alerts);
        unset($this->count);
    }

    private function getCacheKey(): string
    {
        return 'user:'.Auth::id().':dismissed_alerts';
    }

    private function getPersistedDismissals(): array
    {
        return Cache::get($this->getCacheKey(), []);
    }

    private function persistDismissals(): void
    {
        Cache::put($this->getCacheKey(), $this->dismissedAlerts, now()->addDays(7));
    }

    public function retrySiteBackup(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        CreateBackup::dispatch($site, 'full', 'manual');

        Cache::forget('global_alerts');
        unset($this->alerts);
        unset($this->count);

        session()->flash('message', "Retry dispatched for {$site->name}.");
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
