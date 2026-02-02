<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Services\WordPressApiService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSettings extends Component
{
    public Site $site;

    public string $apiEndpoint = '';
    public string $apiKey = '';
    public string $apiSecret = '';

    public string $connectionStatus = '';
    public ?string $testResult = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->apiEndpoint = $site->api_endpoint ?? '';
        $this->apiKey = $site->api_key ?? '';
        $this->apiSecret = $site->api_secret ?? '';
        $this->connectionStatus = $site->is_connected ? 'connected' : 'disconnected';
    }

    public function saveCredentials(): void
    {
        $this->validate([
            'apiKey' => 'required|string',
            'apiSecret' => 'required|string',
        ]);

        // Auto-construct endpoint from site URL if not provided
        $endpoint = $this->apiEndpoint;
        if (empty($endpoint)) {
            $endpoint = rtrim($this->site->url, '/') . '/wp-json/simplead/v1';
        }

        $this->site->update([
            'api_endpoint' => $endpoint,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
        ]);

        $this->apiEndpoint = $endpoint;

        session()->flash('settings-saved', 'WordPress connection credentials saved.');
    }

    public function testConnection(): void
    {
        $this->testResult = null;

        if (empty($this->site->api_key) || empty($this->site->api_secret)) {
            $this->testResult = 'error:Please save your API credentials first.';
            return;
        }

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->healthCheck();

            if (isset($result['status']) && $result['status'] === 'ok') {
                $this->site->update(['is_connected' => true]);
                $this->connectionStatus = 'connected';
                $this->testResult = 'success:Connection successful! WordPress ' . ($result['wp_version'] ?? '') . ' — Connector v' . ($result['connector_version'] ?? '');
            } else {
                $this->testResult = 'error:Unexpected response from the site.';
            }
        } catch (\Exception $e) {
            $this->site->update(['is_connected' => false]);
            $this->connectionStatus = 'disconnected';
            $this->testResult = 'error:Connection failed — ' . $e->getMessage();
        }
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

    public function render()
    {
        return view('livewire.sites.detail.site-settings')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Settings',
            ]);
    }
}
