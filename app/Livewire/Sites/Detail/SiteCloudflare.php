<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare as SiteCloudflareModel;
use App\Services\CloudflareService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteCloudflare extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public string $tab = 'overview';

    // Connection
    public ?int $selectedConnectionId = null;

    public ?string $selectedZoneId = null;

    // Cache
    public string $purgeUrls = '';

    // Analytics
    public string $analyticsPeriod = '-1440';

    public string $analyticsError = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        if ($cf = $this->site->siteCloudflare) {
            $this->selectedConnectionId = $cf->cloudflare_connection_id;
        }
    }

    #[Computed]
    public function siteCloudflare(): ?SiteCloudflareModel
    {
        return $this->site->siteCloudflare;
    }

    #[Computed]
    public function connections()
    {
        return CloudflareConnection::where('is_valid', true)->orderBy('account_email')->get();
    }

    public function updatedSelectedConnectionId(): void
    {
        $this->selectedZoneId = null;
        unset($this->availableZones);
    }

    #[Computed]
    public function availableZones(): array
    {
        if (! $this->selectedConnectionId) {
            return [];
        }

        $connection = CloudflareConnection::find($this->selectedConnectionId);
        if (! $connection) {
            return [];
        }

        try {
            $service = new CloudflareService($connection);

            return $service->listZones();
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to fetch zones: '.$e->getMessage());

            return [];
        }
    }

    public function connectToZone(): void
    {
        if (! $this->selectedConnectionId || ! $this->selectedZoneId) {
            session()->flash('cf-error', 'Please select a connection and zone.');

            return;
        }

        $connection = CloudflareConnection::findOrFail($this->selectedConnectionId);
        $service = new CloudflareService($connection);

        try {
            $service->connectSiteToZone($this->site, $this->selectedZoneId);
            $this->site->load('siteCloudflare');
            unset($this->siteCloudflare);
            session()->flash('cf-success', 'Site connected to Cloudflare zone.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to connect: '.$e->getMessage());
        }
    }

    public function disconnectZone(): void
    {
        $this->site->siteCloudflare?->delete();
        $this->site->load('siteCloudflare');
        unset($this->siteCloudflare);
        session()->flash('cf-success', 'Cloudflare zone disconnected.');
    }

    // DNS (read-only)

    private function dnsCacheKey(): string
    {
        return "cf:{$this->siteCloudflare?->id}:dns";
    }

    #[Computed]
    public function dnsRecords(): array
    {
        $cf = $this->siteCloudflare;
        if (! $cf) {
            return [];
        }

        return Cache::remember($this->dnsCacheKey(), 120, function () use ($cf) {
            try {
                $service = new CloudflareService($cf->cloudflareConnection);

                return $service->listDnsRecords($cf->zone_id);
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    // Cache

    #[Computed]
    public function cachePurges()
    {
        $cf = $this->siteCloudflare;
        if (! $cf) {
            return collect();
        }

        return $cf->cachePurges()->with('purgedBy')->orderByDesc('purged_at')->limit(20)->get();
    }

    public function purgeEverything(): void
    {
        $cf = $this->siteCloudflare;
        if (! $cf) {
            return;
        }

        $rateLimitKey = "cf-purge:{$cf->id}:".auth()->id();
        if (! \Illuminate\Support\Facades\RateLimiter::attempt($rateLimitKey, 5, fn () => true, 60)) {
            session()->flash('cf-error', 'Too many purge requests. Please wait a moment.');

            return;
        }

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->purgeEverything($cf->zone_id);

            $cf->cachePurges()->create([
                'type' => 'everything',
                'purged_by' => auth()->id(),
                'purged_at' => now(),
            ]);

            unset($this->cachePurges);
            session()->flash('cf-success', 'Cache purged successfully.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to purge cache: '.$e->getMessage());
        }
    }

    public function purgeByUrls(): void
    {
        $urls = array_filter(array_map('trim', explode("\n", $this->purgeUrls)));

        if (empty($urls)) {
            session()->flash('cf-error', 'Please enter at least one URL.');

            return;
        }

        $cf = $this->siteCloudflare;
        if (! $cf) {
            return;
        }

        $service = new CloudflareService($cf->cloudflareConnection);

        try {
            $service->purgeByUrls($cf->zone_id, $urls);

            $cf->cachePurges()->create([
                'type' => 'urls',
                'targets' => $urls,
                'purged_by' => auth()->id(),
                'purged_at' => now(),
            ]);

            $this->purgeUrls = '';
            unset($this->cachePurges);
            session()->flash('cf-success', count($urls).' URL(s) purged from cache.');
        } catch (\Exception $e) {
            session()->flash('cf-error', 'Failed to purge URLs: '.$e->getMessage());
        }
    }

    // Analytics

    #[Computed]
    public function analytics(): array
    {
        $cf = $this->siteCloudflare;
        if (! $cf) {
            return [];
        }

        try {
            $this->analyticsError = '';
            $service = new CloudflareService($cf->cloudflareConnection);

            return $service->getAnalytics($cf->zone_id, $this->analyticsPeriod);
        } catch (\Exception $e) {
            $this->analyticsError = $e->getMessage();

            return [];
        }
    }

    public function render()
    {
        return view('livewire.sites.detail.site-cloudflare')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Cloudflare',
            ]);
    }
}
