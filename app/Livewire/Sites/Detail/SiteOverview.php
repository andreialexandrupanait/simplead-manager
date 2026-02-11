<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncWordPressSite;
use App\Models\AnalyticsCache;
use App\Models\Site;
use App\Services\WordPressApiService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteOverview extends Component
{
    public Site $site;

    // Period selectors for cards
    public string $analyticsPeriod = '28d';

    // Connect plugin modal
    public string $apiKey = '';
    public string $apiSecret = '';
    public string $apiEndpoint = '';

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function setAnalyticsPeriod(string $period): void
    {
        $this->analyticsPeriod = $period;
    }

    #[Computed]
    public function analyticsData(): ?array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', $this->analyticsPeriod)
            ->latest('fetched_at')
            ->first();

        return $cache?->data;
    }

    #[Computed]
    public function updatesData(): array
    {
        $core = $this->site->core_update_version ? 1 : 0;
        $plugins = $this->site->sitePlugins()->where('has_update', true)->count();
        $themes = $this->site->siteThemes()->where('has_update', true)->count();

        return [
            'core' => $core,
            'plugins' => $plugins,
            'themes' => $themes,
            'total' => $core + $plugins + $themes,
        ];
    }

    public function runBackup(): void
    {
        // Dispatch backup job
        dispatch(new \App\Jobs\CreateBackup($this->site, 'full', 'manual'));

        $this->dispatch('notify', type: 'success', message: 'Backup started');
    }

    public function updateAll(): void
    {
        $this->redirect(route('sites.updates', $this->site), navigate: true);
    }

    public function syncNow(): void
    {
        SyncWordPressSite::dispatch($this->site);
        session()->flash('sync-dispatched', 'Sync job has been dispatched.');
    }

    public function openWpAdmin(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $result = $api->getLoginUrl();

            if (!empty($result['login_url'])) {
                $this->js("window.open('" . addslashes($result['login_url']) . "', '_blank')");
                return;
            }

            session()->flash('wp-admin-error', 'Could not generate login URL. No URL returned.');
        } catch (\Exception $e) {
            session()->flash('wp-admin-error', 'Could not generate login URL: ' . $e->getMessage());
        }
    }

    public function openConnectModal(): void
    {
        $this->apiKey = $this->site->api_key ?? '';
        $this->apiSecret = $this->site->api_secret ?? '';
        $this->apiEndpoint = $this->site->api_endpoint ?? '';

        // Auto-fill endpoint from site URL if empty
        if (empty($this->apiEndpoint) && $this->site->url) {
            $this->apiEndpoint = rtrim($this->site->url, '/') . '/wp-json/jesuspended/v1';
        }

        $this->dispatch('open-modal-connect-plugin');
    }

    public function saveCredentials(): void
    {
        $this->validate([
            'apiKey' => 'required|min:10',
            'apiSecret' => 'required|min:10',
            'apiEndpoint' => 'required|url',
        ]);

        $this->site->update([
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'api_endpoint' => $this->apiEndpoint,
        ]);

        SyncWordPressSite::dispatch($this->site);

        $this->dispatch('close-modal-connect-plugin');
        session()->flash('success', 'Credentials saved. Syncing site...');
    }

    public function disconnectSite(): void
    {
        $this->site->update([
            'api_key' => null,
            'api_secret' => null,
            'api_endpoint' => null,
            'is_connected' => false,
        ]);

        $this->apiKey = '';
        $this->apiSecret = '';
        $this->apiEndpoint = '';

        $this->dispatch('close-modal-connect-plugin');
        session()->flash('success', 'Site disconnected.');
    }

    public function openAssignClientModal(): void
    {
        // Open assign client modal
        $this->dispatch('open-assign-client-modal');
    }

    public function render()
    {
        return view('livewire.sites.detail.site-overview')->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Overview',
        ]);
    }
}
