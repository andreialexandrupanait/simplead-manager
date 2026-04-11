<?php

declare(strict_types=1);

namespace App\Livewire\Plugins;

use App\Jobs\PushConnectorPlugin;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Models\SitePlugin;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class PluginLicensesOverview extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public string $search = '';

    #[Computed]
    public function stats(): array
    {
        $base = SitePlugin::licensed()->whereHas('site');

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('license_status', 'active')->count(),
            'expiring' => (clone $base)->expiringLicenses(30)->count(),
            'expired' => (clone $base)->expiredLicenses()->count(),
        ];
    }

    public function scanLicenses(): void
    {
        $sites = Site::where('is_connected', true)->get();

        $pushId = Str::uuid()->toString();
        $downloadUrl = URL::temporarySignedRoute(
            'download.connector-plugin.signed',
            now()->addMinutes(30)
        );

        foreach ($sites as $site) {
            PushConnectorPlugin::dispatch($site, $downloadUrl, $pushId);
            SyncWordPressSite::dispatch($site)->delay(now()->addSeconds(45 + rand(0, 60)));
        }

        $this->dispatch('notify', type: 'success', message: "Updating connector & scanning licenses on {$sites->count()} sites. Results in ~2 minutes.");
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = SitePlugin::licensed()
            ->whereHas('site')
            ->with('site')
            ->when($this->filter === 'active', fn ($q) => $q->where('license_status', 'active'))
            ->when($this->filter === 'expiring', fn ($q) => $q->expiringLicenses(30))
            ->when($this->filter === 'expired', fn ($q) => $q->expiredLicenses())
            ->when($this->search, function ($q) {
                $search = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search) . '%';
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'ilike', $search)
                        ->orWhere('slug', 'ilike', $search)
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'ilike', $search));
                });
            })
            ->orderByRaw("CASE WHEN license_expires_at IS NOT NULL AND license_expires_at < NOW() THEN 0 WHEN license_expires_at IS NOT NULL AND license_expires_at <= NOW() + INTERVAL '30 days' THEN 1 ELSE 2 END")
            ->orderBy('license_expires_at')
            ->paginate(50);

        return view('livewire.plugins.plugin-licenses-overview', [
            'licenses' => $query,
        ])->layout('components.layouts.app', ['title' => 'Plugin Licenses']);
    }
}
