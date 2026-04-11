<?php

declare(strict_types=1);

namespace App\Livewire\Plugins;

use App\Jobs\PushConnectorPlugin;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Models\SitePlugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PluginLicensesOverview extends Component
{
    public string $filter = 'all';

    public string $search = '';

    public bool $scanning = false;

    public string $scanPhase = '';

    public int $scanTotal = 0;

    public int $scanCompleted = 0;

    public ?string $scanPushId = null;

    public ?string $scanSyncId = null;

    #[Computed]
    public function stats(): array
    {
        $premium = SitePlugin::where('is_on_wp_org', false)->whereHas('site');
        $licensed = SitePlugin::licensed()->whereHas('site');

        return [
            'premium_plugins' => (clone $premium)->count(),
            'with_license' => (clone $licensed)->count(),
            'no_license' => (clone $premium)->whereNull('license_key')->count(),
            'active' => (clone $licensed)->where('license_status', 'active')->count(),
            'expiring' => (clone $licensed)->expiringLicenses(30)->count(),
            'expired' => (clone $licensed)->expiredLicenses()->count(),
        ];
    }

    #[Computed]
    public function sites(): array
    {
        $query = SitePlugin::query()
            ->whereHas('site', fn ($q) => $q->where('is_connected', true))
            ->with('site')
            ->where(function ($q) {
                $q->where('is_on_wp_org', false)->orWhereNotNull('license_key');
            })
            ->when($this->filter === 'licensed', fn ($q) => $q->whereNotNull('license_key'))
            ->when($this->filter === 'no_license', fn ($q) => $q->where('is_on_wp_org', false)->whereNull('license_key'))
            ->when($this->filter === 'expiring', fn ($q) => $q->expiringLicenses(30))
            ->when($this->filter === 'expired', fn ($q) => $q->expiredLicenses())
            ->when($this->search, function ($q) {
                $s = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search) . '%';
                $q->where(function ($sq) use ($s) {
                    $sq->where('name', 'ilike', $s)
                        ->orWhere('slug', 'ilike', $s)
                        ->orWhereHas('site', fn ($site) => $site->where('name', 'ilike', $s));
                });
            })
            ->orderBy('site_id')
            ->orderBy('name')
            ->get();

        return $query->groupBy(fn ($p) => $p->site?->name ?? 'Unknown')->map(function ($plugins, $siteName) {
            $site = $plugins->first()->site;

            return [
                'site_name' => $siteName,
                'site_id' => $site?->id,
                'plugins' => $plugins->values()->all(),
                'licensed_count' => $plugins->filter(fn ($p) => $p->license_key)->count(),
                'total_count' => $plugins->count(),
            ];
        })->sortBy('site_name')->values()->all();
    }

    public function scanLicenses(): void
    {
        $sites = Site::where('is_connected', true)->get();

        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: 'No connected sites found.');

            return;
        }

        $this->scanning = true;
        $this->scanPhase = 'push';
        $this->scanTotal = $sites->count();
        $this->scanCompleted = 0;
        $this->scanPushId = Str::uuid()->toString();
        $this->scanSyncId = Str::uuid()->toString();

        $pushCacheKey = "connector-push:{$this->scanPushId}";
        Cache::put("{$pushCacheKey}:results", [], 3600);
        Cache::put("{$pushCacheKey}:completed", 0, 3600);

        $syncCacheKey = "license-sync:{$this->scanSyncId}";
        Cache::put("{$syncCacheKey}:completed", 0, 3600);

        $downloadUrl = URL::temporarySignedRoute(
            'download.connector-plugin.signed',
            now()->addMinutes(30)
        );

        foreach ($sites as $site) {
            PushConnectorPlugin::dispatch($site, $downloadUrl, $this->scanPushId);
        }
    }

    public function checkScanProgress(): void
    {
        if (! $this->scanning) {
            return;
        }

        if ($this->scanPhase === 'push') {
            $completed = (int) Cache::get("connector-push:{$this->scanPushId}:completed", 0);
            $this->scanCompleted = $completed;

            if ($completed >= $this->scanTotal) {
                // Push done — start sync phase
                $this->scanPhase = 'sync';
                $this->scanCompleted = 0;

                $sites = Site::where('is_connected', true)->get();
                foreach ($sites as $i => $site) {
                    SyncWordPressSite::dispatch($site)
                        ->delay(now()->addSeconds(5 + ($i * 2)));
                }

                Cache::forget("connector-push:{$this->scanPushId}:results");
                Cache::forget("connector-push:{$this->scanPushId}:completed");
            }
        } elseif ($this->scanPhase === 'sync') {
            // Check how many sites have been synced recently (last 2 minutes)
            $recentlySynced = Site::where('is_connected', true)
                ->where('last_synced_at', '>=', now()->subMinutes(2))
                ->count();

            $this->scanCompleted = min($recentlySynced, $this->scanTotal);

            if ($this->scanCompleted >= $this->scanTotal) {
                $this->scanning = false;
                $this->scanPhase = '';
                $this->scanPushId = null;
                $this->scanSyncId = null;

                Cache::forget("license-sync:{$this->scanSyncId}:completed");
                unset($this->stats, $this->sites);

                $this->dispatch('notify', type: 'success', message: "Scan complete. {$this->scanTotal} sites updated.");
            }
        }
    }

    public function render()
    {
        return view('livewire.plugins.plugin-licenses-overview')
            ->layout('components.layouts.app', ['title' => 'Plugin Licenses']);
    }
}
