<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Jobs\PushConnectorPlugin;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class WordPressSettings extends Component
{
    public bool $pluginPushRunning = false;

    public array $pluginPushResults = [];

    public array $selectedPushSiteIds = [];

    public bool $pushSelectAll = false;

    public string $pushSiteSearch = '';

    public ?string $pushId = null;

    public int $pushTotal = 0;

    #[Computed]
    public function connectedSites()
    {
        $query = Site::connected();

        if ($this->pushSiteSearch) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->pushSiteSearch}%")
                    ->orWhere('url', 'ilike', "%{$this->pushSiteSearch}%");
            });
        }

        return $query->get();
    }

    public function updatedPushSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedPushSiteIds = $this->connectedSites->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedPushSiteIds = [];
        }
    }

    public function updatedPushSiteSearch(): void
    {
        $this->pushSiteSearch = substr(trim($this->pushSiteSearch), 0, 100);
        unset($this->connectedSites);
    }

    public function openPushSiteSelector(): void
    {
        $this->selectedPushSiteIds = [];
        $this->pushSelectAll = false;
        $this->pushSiteSearch = '';
        unset($this->connectedSites);
        $this->dispatch('open-modal-push-site-selector');
    }

    public function pushPluginToAllSites(): void
    {
        $this->pushPluginToSites(Site::connected()->get());
    }

    public function pushPluginToSelectedSites(): void
    {
        if (empty($this->selectedPushSiteIds)) {
            $this->dispatch('notify', type: 'warning', message: 'No sites selected.');

            return;
        }

        $sites = Site::connected()->whereIn('id', $this->selectedPushSiteIds)->get();

        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: 'No valid connected sites found in selection.');

            return;
        }

        $this->dispatch('close-modal-push-site-selector');
        $this->pushPluginToSites($sites);

        $this->selectedPushSiteIds = [];
        $this->pushSelectAll = false;
        $this->pushSiteSearch = '';
    }

    private function pushPluginToSites($sites): void
    {
        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: 'No connected sites found.');

            return;
        }

        $this->pluginPushRunning = true;
        $this->pluginPushResults = [];
        $this->pushId = Str::uuid()->toString();
        $this->pushTotal = $sites->count();

        $cacheKey = "connector-push:{$this->pushId}";
        Cache::put("{$cacheKey}:results", [], 3600);
        Cache::put("{$cacheKey}:completed", 0, 3600);

        $downloadUrl = URL::temporarySignedRoute(
            'download.connector-plugin.signed',
            now()->addMinutes(30)
        );

        foreach ($sites as $site) {
            PushConnectorPlugin::dispatch($site, $downloadUrl, $this->pushId);
        }
    }

    public function checkPushProgress(): void
    {
        if (! $this->pushId) {
            return;
        }

        $cacheKey = "connector-push:{$this->pushId}";
        $completed = (int) Cache::get("{$cacheKey}:completed", 0);
        $results = Cache::get("{$cacheKey}:results", []);

        $this->pluginPushResults = $results;

        if ($completed >= $this->pushTotal) {
            $this->pluginPushRunning = false;

            $succeeded = collect($results)->where('status', 'success')->count();
            $failed = collect($results)->where('status', 'error')->count();

            $this->dispatch('notify',
                type: $failed > 0 ? 'warning' : 'success',
                message: "Plugin push complete: {$succeeded} updated, {$failed} failed."
            );

            Cache::forget("{$cacheKey}:results");
            Cache::forget("{$cacheKey}:completed");
            $this->pushId = null;
        }
    }

    public function render()
    {
        return view('livewire.settings.wordpress-settings')
            ->layout('components.layouts.app', ['title' => 'WordPress Settings']);
    }
}
