<?php

namespace App\Livewire\Settings;

use App\Jobs\PushConnectorPlugin;
use App\Livewire\Forms\GeneralSettingsFormData;
use App\Livewire\Forms\SiteStatusFormData;
use App\Models\Site;
use App\Models\SiteStatus;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class GeneralSettings extends Component
{
    use WithFileUploads;

    private static ?bool $hasSiteStatusesTable = null;

    public GeneralSettingsFormData $form;

    public SiteStatusFormData $statusForm;

    // Branding paths (not part of the form -- display-only state)
    public ?string $faviconPath = null;

    public ?string $logoPath = null;

    // Site Status form editing ID
    public ?int $editingStatusId = null;

    public function mount(SettingsService $settings): void
    {
        $this->form->appName = $settings->get('app_name', 'SimpleAd Manager');
        $this->form->appUrl = $settings->get('app_url', config('app.url', ''));
        $this->form->defaultTimezone = $settings->get('default_timezone', 'UTC');
        $this->form->dateFormat = $settings->get('date_format', 'M d, Y');
        $this->form->defaultInterval = (int) $settings->get('default_interval', 300);
        $this->form->defaultTimeout = (int) $settings->get('default_timeout', 30);
        $this->form->alertAfterFailures = (int) $settings->get('alert_after_failures', 3);
        $this->faviconPath = $settings->get('branding.favicon');
        $this->logoPath = $settings->get('branding.logo');
    }

    #[Computed]
    public function siteStatuses()
    {
        if (! (static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            return collect();
        }

        return SiteStatus::withCount('sites')->orderBy('sort_order')->get();
    }

    public function save(SettingsService $settings): void
    {
        $this->form->validate();

        $settings->set('app_name', $this->form->appName, 'general', 'string');
        $settings->set('app_url', $this->form->appUrl, 'general', 'string');
        $settings->set('default_timezone', $this->form->defaultTimezone, 'general', 'string');
        $settings->set('date_format', $this->form->dateFormat, 'general', 'string');
        $settings->set('default_interval', $this->form->defaultInterval, 'monitoring', 'integer');
        $settings->set('default_timeout', $this->form->defaultTimeout, 'monitoring', 'integer');
        $settings->set('alert_after_failures', $this->form->alertAfterFailures, 'monitoring', 'integer');

        if ($this->form->favicon) {
            if ($this->faviconPath) {
                Storage::disk('public')->delete($this->faviconPath);
            }

            $path = $this->form->favicon->storeAs('branding', uniqid('favicon_').'.'.$this->form->favicon->getClientOriginalExtension(), 'public');
            $settings->set('branding.favicon', $path, 'branding', 'string');
            $this->faviconPath = $path;
            $this->form->favicon = null;
        }

        if ($this->form->logo) {
            if ($this->logoPath) {
                Storage::disk('public')->delete($this->logoPath);
            }

            $path = $this->form->logo->storeAs('branding', uniqid('logo_').'.'.$this->form->logo->getClientOriginalExtension(), 'public');
            $settings->set('branding.logo', $path, 'branding', 'string');
            $this->logoPath = $path;
            $this->form->logo = null;
        }

        $this->dispatch('notify', type: 'success', message: 'Settings saved successfully.');
    }

    public function removeFavicon(SettingsService $settings): void
    {
        if ($this->faviconPath) {
            Storage::disk('public')->delete($this->faviconPath);
            $settings->set('branding.favicon', null, 'branding', 'string');
            $this->faviconPath = null;
        }
    }

    public function removeLogo(SettingsService $settings): void
    {
        if ($this->logoPath) {
            Storage::disk('public')->delete($this->logoPath);
            $settings->set('branding.logo', null, 'branding', 'string');
            $this->logoPath = null;
        }
    }

    public function openStatusForm(?int $id = null): void
    {
        if (! (static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');

            return;
        }

        if ($id) {
            $status = SiteStatus::findOrFail($id);
            $this->editingStatusId = $status->id;
            $this->statusForm->statusName = $status->name;
            $this->statusForm->statusColor = $status->color;
            $this->statusForm->statusSortOrder = $status->sort_order;
        } else {
            $this->editingStatusId = null;
            $this->statusForm->statusName = '';
            $this->statusForm->statusColor = '#6b7280';
            $this->statusForm->statusSortOrder = 0;
        }

        $this->resetValidation();
        $this->dispatch('open-modal-status-form');
    }

    public function saveStatus(): void
    {
        if (! (static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');

            return;
        }

        $this->statusForm->validate();

        SiteStatus::updateOrCreate(
            ['id' => $this->editingStatusId],
            [
                'name' => $this->statusForm->statusName,
                'color' => $this->statusForm->statusColor,
                'sort_order' => $this->statusForm->statusSortOrder,
            ]
        );

        $this->dispatch('close-modal-status-form');
        unset($this->siteStatuses);
    }

    public function deleteStatus(int $id): void
    {
        if (! (static::$hasSiteStatusesTable ??= Schema::hasTable('site_statuses'))) {
            $this->dispatch('notify', type: 'error', message: 'Please run migrations first: php artisan migrate');

            return;
        }

        $status = SiteStatus::withCount('sites')->findOrFail($id);

        if ($status->sites_count > 0) {
            $this->dispatch('notify', type: 'error', message: "Cannot delete \"{$status->name}\" — {$status->sites_count} site(s) are assigned to it.");

            return;
        }

        $status->delete();
        unset($this->siteStatuses);
    }

    public bool $pluginPushRunning = false;

    public array $pluginPushResults = [];

    public array $selectedPushSiteIds = [];

    public bool $pushSelectAll = false;

    public string $pushSiteSearch = '';

    public ?string $pushId = null;

    public int $pushTotal = 0;

    public const CONNECTOR_CHANGELOG = [
        'unreleased' => [
            'changes' => [
                // New changes accumulate here until version bump
            ],
        ],
        '2.9.9' => [
            'date' => '2026-03-22',
            'changes' => [
                'UI: Redesigned admin dashboard with 2-column grid layout',
                'Fix: CSS specificity for WordPress admin compatibility',
            ],
        ],
        '2.9.8' => [
            'date' => '2026-03-22',
            'changes' => [
                'New: Standalone opcache-flush.php for automatic OPcache clearing after push',
                'Fix: Push now calls opcache-flush.php directly (bypasses WordPress/OPcache)',
            ],
        ],
        '2.9.7' => [
            'date' => '2026-03-22',
            'changes' => [
                'New: /flush-opcache endpoint for reliable OPcache clearing after push',
                'Fix: Push now auto-flushes OPcache on target site after connector update',
            ],
        ],
        '2.9.6' => [
            'date' => '2026-03-22',
            'changes' => [
                'Fix: Aggressive OPcache clearing after self-update (touch + invalidate each file)',
                'Fix: Store connector version in DB option to survive OPcache issues',
                'Fix: MU-plugin detects OPcache flag and invalidates stale connector files',
                'Fix: Info endpoint reads version from DB option as fallback',
            ],
        ],
        '2.9.5' => [
            'date' => '2026-03-22',
            'changes' => [
                'Fix: Read plugin/theme versions directly from files to bypass OPcache/object cache',
                'Fix: version_compare() verification prevents false update notifications',
            ],
        ],
        '2.9.4' => [
            'date' => '2026-03-22',
            'changes' => [
                'Fix: Update response now returns version info (from_version, to_version)',
                'Fix: Already-up-to-date plugins/themes treated as success instead of failure',
                'Fix: Plugin/theme list forces fresh update transient for accurate detection',
            ],
        ],
        '2.9.3' => [
            'date' => '2026-03-21',
            'changes' => [
                'Fix: Plugin validation now clears cache before checking (symlink compatible)',
                'Fix: Plugin list cache cleared before sync for accurate results',
            ],
        ],
        '2.9.2' => [
            'date' => '2026-03-21',
            'changes' => [
                'Fix: Invalid plugin path error on activate/deactivate/delete',
            ],
        ],
        '2.9.1' => [
            'date' => '2026-03-21',
            'changes' => [
                'Fix: Plugins no longer get deactivated during updates',
                'Fix: Custom login URL page now loads CSS/JS correctly',
                'Fix: wp-login.php and wp-admin no longer reveal custom login URL',
                'Renamed plugin to SAD Mentenanta',
            ],
        ],
        '2.9.0' => [
            'date' => '2026-03-15',
            'changes' => [
                'MU-plugin persistence for security module enforcement',
                'Enhanced security module verification',
            ],
        ],
    ];

    #[Computed]
    public function connectedSites()
    {
        $query = Site::connected()->orderBy('name');

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

            // Cleanup cache
            Cache::forget("{$cacheKey}:results");
            Cache::forget("{$cacheKey}:completed");
            $this->pushId = null;
        }
    }

    public function purgeMonitoringData(): void
    {
        UptimeCheck::query()->delete();
        UptimeIncident::query()->delete();

        $this->dispatch('notify', type: 'warning', message: 'Monitoring data has been purged.');
    }

    public function render()
    {
        return view('livewire.settings.general-settings')
            ->layout('components.layouts.app', ['title' => 'General Settings']);
    }
}
