<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithPluginManagement;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithThemeManagement;
use App\Livewire\Traits\WithWpAdminLogin;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\UpdateLog;
use App\Services\PluginManagerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SitePlugins extends Component
{
    use WithJobTracking, WithPluginManagement, WithSiteAuthorization, WithThemeManagement, WithWpAdminLogin;

    public Site $site;

    public bool $embedded = false;

    public string $tab = 'plugins';

    public string $filter = 'all';

    public string $search = '';

    public array $updateResults = [];

    public ?array $detailItem = null;

    public ?string $changelog = null;

    public bool $changelogLoading = false;

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-wp-'.$this->site->id,
            'backup' => 'backup-'.$this->site->id,
        ];
    }

    public function mount(Site $site, bool $embedded = false): void
    {
        $this->authorizeSiteAccess($site);
        $site->loadMissing('wpAdminUser');
        $this->site = $site;
        $this->embedded = $embedded;
        $this->initJobTracking();
    }

    #[Computed]
    public function plugins()
    {
        $query = $this->site->sitePlugins();

        if ($this->filter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->filter === 'inactive') {
            $query->where('is_active', false);
        } elseif ($this->filter === 'updates') {
            $query->where('has_update', true);
        }

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('slug', 'ilike', '%'.$escaped.'%')
                    ->orWhere('author', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function themes()
    {
        $query = $this->site->siteThemes();

        if ($this->filter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->filter === 'updates') {
            $query->where('has_update', true);
        }

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('slug', 'ilike', '%'.$escaped.'%')
                    ->orWhere('author', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function users()
    {
        $query = $this->site->siteUsers();

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('username', 'ilike', '%'.$escaped.'%')
                    ->orWhere('display_name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('email', 'ilike', '%'.$escaped.'%')
                    ->orWhere('role', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->orderBy('display_name')->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    #[Computed]
    public function pluginCounts()
    {
        /** @var \stdClass $counts */
        $counts = $this->site->sitePlugins()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = false THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN has_update = true THEN 1 ELSE 0 END) as updates
            ')
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->inactive,
            'updates' => (int) $counts->updates,
        ];
    }

    #[Computed]
    public function themeCounts()
    {
        /** @var \stdClass $counts */
        $counts = $this->site->siteThemes()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN has_update = true THEN 1 ELSE 0 END) as updates
            ')
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'updates' => (int) $counts->updates,
        ];
    }

    #[Computed]
    public function userCount()
    {
        return $this->site->siteUsers()->count();
    }

    #[Computed]
    public function updateHistory()
    {
        $query = UpdateLog::where('site_id', $this->site->id);

        if ($this->filter !== 'all') {
            $query->where('type', $this->filter === 'plugins' ? 'plugin' : 'theme');
        }

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('slug', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->with('user')->orderByDesc('performed_at')->limit(100)->get();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->filter = 'all';
        $this->search = '';
        $this->dispatch('bulk-selection-reset');
        unset($this->plugins, $this->themes, $this->users, $this->updateHistory);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->dispatch('bulk-selection-reset');
        unset($this->plugins, $this->themes, $this->updateHistory);
    }

    public function clearResult(string $key): void
    {
        unset($this->updateResults[$key]);
    }

    private function performUpdate(string $type, string $identifier, string $name, string $slug, ?string $currentVersion, ?string $updateVersion): array
    {
        $result = app(PluginManagerService::class)->performUpdate($this->site, $type, $identifier, $name, $slug, $currentVersion, $updateVersion);

        if (! $result['success']) {
            $this->dispatch('notify', type: 'error', message: "Update failed for {$name}: ".$result['message']);
        }

        return $result;
    }

    public function syncNow(): void
    {
        $rateLimitKey = "sync:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 10, fn () => true, 3600)) {
            session()->flash('update-error', 'Too many sync requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('sync', new SyncWordPressSite($this->site), 'Syncing site data...');
    }

    public function updateCore(): void
    {
        $this->runPreUpdateBackup();
        $result = app(PluginManagerService::class)->updateCore($this->site);

        if ($result['success']) {
            session()->flash('update-success', $result['message']);
        } else {
            session()->flash('update-error', $result['message']);
        }
    }

    public function toggleAutoUpdate(string $type, int $id): void
    {
        if ($type === 'plugin') {
            /** @var SitePlugin $item */
            $item = $this->site->sitePlugins()->findOrFail($id);
        } else {
            /** @var SiteTheme $item */
            $item = $this->site->siteThemes()->findOrFail($id);
        }

        $item->update(['auto_update' => ! $item->auto_update]);

        $this->dispatch('notify', type: 'success', message: ($item->auto_update ? 'Enabled' : 'Disabled')." auto-updates for {$item->name}.");
        unset($this->plugins, $this->themes);
    }

    public function showDetail(string $type, int $id): void
    {
        if ($type === 'plugin') {
            /** @var SitePlugin $item */
            $item = $this->site->sitePlugins()->findOrFail($id);
        } else {
            /** @var SiteTheme $item */
            $item = $this->site->siteThemes()->findOrFail($id);
        }

        $this->changelog = null;

        $this->detailItem = [
            'id' => $item->id,
            'type' => $type,
            'name' => $item->name,
            'slug' => $item->slug,
            'version' => $item->version,
            'author' => $item->author,
            'description' => $item->description,
            'url' => $item instanceof SitePlugin ? $item->plugin_uri : null,
            'is_active' => $item->is_active,
            'auto_update' => $item->auto_update,
            'has_update' => $item->has_update,
            'update_version' => $item->update_version,
            'is_abandoned' => $item->is_abandoned ?? false,
            'is_closed' => $item->is_closed ?? false,
            'closed_reason' => $item->closed_reason ?? null,
            'wp_org_last_updated' => $item->wp_org_last_updated?->format('M j, Y'),
            'license_status' => $item instanceof SitePlugin ? $item->license_status : null,
            'license_expires_at' => $item instanceof SitePlugin ? $item->license_expires_at?->format('M j, Y') : null,
            'can_rollback' => $item instanceof SitePlugin && UpdateLog::where('site_id', $this->site->id)
                ->where('type', 'plugin')->where('name', $item->slug)->where('success', true)->exists(),
            'rollback_version' => $item instanceof SitePlugin ? UpdateLog::where('site_id', $this->site->id)
                ->where('type', 'plugin')->where('name', $item->slug)->where('success', true)
                ->orderByDesc('performed_at')->value('from_version') : null,
        ];

        $this->dispatch('open-modal-plugin-detail');
    }

    public function fetchChangelog(): void
    {
        if (! $this->detailItem) {
            return;
        }

        $slug = $this->detailItem['slug'];
        $type = $this->detailItem['type'];
        $this->changelogLoading = true;

        try {
            $cacheKey = "wp_org_changelog:{$type}:{$slug}";
            $this->changelog = Cache::remember($cacheKey, 3600, function () use ($slug, $type) {
                if ($type === 'plugin') {
                    $response = Http::timeout(10)->get('https://api.wordpress.org/plugins/info/1.2/', [
                        'action' => 'plugin_information',
                        'request[slug]' => $slug,
                        'request[fields][sections]' => true,
                    ]);
                } else {
                    $response = Http::timeout(10)->get('https://api.wordpress.org/themes/info/1.2/', [
                        'action' => 'theme_information',
                        'request[slug]' => $slug,
                        'request[fields][sections]' => true,
                    ]);
                }

                if ($response->failed()) {
                    return null;
                }

                $data = $response->json();

                return $data['sections']['changelog'] ?? null;
            });
        } catch (\Throwable) {
            $this->changelog = null;
        }

        $this->changelogLoading = false;
    }

    public function quickBackup(): void
    {
        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('update-error', 'Too many backup requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('backup', new \App\Jobs\CreateBackup($this->site, 'full', 'manual'), 'Creating backup...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->plugins, $this->themes, $this->users, $this->pluginCounts, $this->themeCounts, $this->userCount, $this->updateHistory);
        $this->site->refresh();
    }

    public function render()
    {
        $view = view('livewire.sites.detail.site-plugins');

        if (! $this->embedded) {
            $view->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Plugins & Themes',
            ]);
        }

        return $view;
    }
}
